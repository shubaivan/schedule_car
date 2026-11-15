<?php

namespace App\Plan\Chronicle;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanAccess;
use App\Plan\Access\PlanCapability;
use App\Plan\Event\ApprovalRequested;
use App\Plan\Event\CommentAdded;
use App\Plan\Event\FileAdded;
use App\Plan\Event\ItemCreated;
use App\Plan\Event\MemberAdded;
use App\Plan\Event\MemberRemoved;
use App\Plan\Event\PurchaseRecorded;
use App\Plan\Event\StatusChanged;
use App\Plan\Event\StockMoved;
use App\Plan\Event\SubjectClosed;
use App\Plan\Event\SubjectCreated;
use App\Plan\Event\SubjectUpdated;
use App\Plan\Subject\SubjectRef;
use App\Tasks\Repository\TaskFeedRepository;
use App\Tasks\Repository\TaskVisibility;
use App\Warehouse\Service\WarehouseSection;
use DateTime;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Хроніка v2 — «вхідні» всього «Плану»: задачі, заявки, склад. Одна строка на
 * суб'єкт з останньою новиною (plan_subject_activity → plan_event), «+N» і
 * «Непрочитані», фільтри за модулем і типом події.
 *
 * Хто що бачить (рядки):
 * - задачі — TaskVisibility::chronicle (директор/адмін — усі, решта — де беруть участь або ★);
 * - заявки — директор/адмін, менеджери постачання й ті, хто погоджує, — усі;
 *   решта — свої (автор) і ті, де коментували;
 * - склад — лише з можливістю warehouse.view (коли склад увімкнено).
 *
 * «+N» (одне правило з дошкою й лічильником меню):
 * - задачі — як і раніше TaskFeedRepository::UNREAD по task_read, лише там, де людина
 *   бере участь (директор на чужих задачах «+N» не має);
 * - заявки — автор, хто коментував, і менеджер постачання (він їх веде);
 * - склад — менеджер постачання, що веде склад (не директор/адмін: для них це шум);
 * - нове = подія-новина від когось іншого, новіша за plan_read.last_event_id.
 */
class PlanChronicle
{
    /** Події, що піднімають рядок і рахуються в «+N». */
    public const array NEWS = [
        SubjectCreated::TYPE, ItemCreated::TYPE, SubjectUpdated::TYPE, StatusChanged::TYPE, SubjectClosed::TYPE,
        ApprovalRequested::TYPE, CommentAdded::TYPE, FileAdded::TYPE, MemberAdded::TYPE, MemberRemoved::TYPE,
        PurchaseRecorded::TYPE, StockMoved::TYPE,
    ];

    /** Фільтр «тип події». */
    public const array EVENT_GROUPS = [
        'comment' => [CommentAdded::TYPE],
        'status' => [StatusChanged::TYPE, SubjectClosed::TYPE, ApprovalRequested::TYPE],
        'file' => [FileAdded::TYPE],
        'created' => [SubjectCreated::TYPE, ItemCreated::TYPE],
        'stock' => [StockMoved::TYPE, PurchaseRecorded::TYPE],
    ];

    public const array MODULES = [
        'task' => ['task'],
        'supply' => ['supply'],
        'warehouse' => ['wh_item', 'wh_movement', 'wh_client', 'wh_site'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PlanAccess $access,
        private readonly WarehouseSection $warehouse,
        private readonly TaskFeedRepository $tasks,
    ) {
    }

    public static function isNews(string $type): bool
    {
        return in_array($type, self::NEWS, true);
    }

    /** @return list<string> */
    public static function subjectTypes(): array
    {
        return array_merge(...array_values(self::MODULES));
    }

    public static function moduleOf(string $subjectType): string
    {
        foreach (self::MODULES as $module => $types) {
            if (in_array($subjectType, $types, true)) {
                return $module;
            }
        }

        return $subjectType;
    }

    /** @return list<string> модулі, які людина бачить у Хроніці */
    public function modulesFor(TelegramUser $viewer): array
    {
        $out = ['task', 'supply'];

        if ($this->seesWarehouse($viewer)) {
            $out[] = 'warehouse';
        }

        return $out;
    }

    /**
     * Рядки Хроніки (ліміт + 1 — щоб знати, чи є далі).
     *
     * @param list<string> $modules порожньо — усі доступні
     * @param list<string> $events групи EVENT_GROUPS; порожньо — будь-яка новина
     *
     * @return list<array<string, mixed>>
     */
    public function rows(TelegramUser $viewer, array $modules, array $events, bool $unreadOnly, bool $favoritesOnly, ?string $cursor, int $limit): array
    {
        $params = ['me' => (int) $viewer->getId(), 'news' => self::NEWS];
        $types = ['news' => ArrayParameterType::STRING];
        [$visible, $unread] = $this->rules($viewer, $modules, $params);

        $event = 'e.id = a.last_event_id';
        $lateral = '';

        if ($events !== []) {
            $eventTypes = [];

            foreach ($events as $group) {
                array_push($eventTypes, ...(self::EVENT_GROUPS[$group] ?? []));
            }

            $params['eventTypes'] = $eventTypes === [] ? ['-'] : $eventTypes;
            $types['eventTypes'] = ArrayParameterType::STRING;
            // Остання подія ОБРАНОГО типу цього суб'єкта (індекс subject_type, subject_id, id);
            // рядки — у порядку останньої активності, тож LIMIT зупиняє прохід рано.
            $lateral = 'JOIN LATERAL (SELECT le.id FROM plan_event le
                          WHERE le.subject_type = a.subject_type AND le.subject_id = a.subject_id AND le.type IN (:eventTypes)
                          ORDER BY le.occurred_at DESC, le.id DESC LIMIT 1) fe ON TRUE';
            $event = 'e.id = fe.id';
        }

        $where = [$visible];

        if ($cursor !== null) {
            [$at, $id] = self::parseCursor($cursor);
            $where[] = '(a.last_at, a.last_event_id) < (CAST(:cursorAt AS timestamp), CAST(:cursorId AS bigint))';
            $params['cursorAt'] = $at;
            $params['cursorId'] = $id;
        }

        if ($favoritesOnly) {
            $where[] = "a.subject_type = 'task' AND EXISTS (SELECT 1 FROM task_favorite fv WHERE fv.task_id = a.subject_id AND fv.user_id = :me)";
        }

        if ($unreadOnly) {
            // Дешевий відсів до точного «+N»: є щось новіше за прочитане.
            $where[] = "(CASE WHEN a.subject_type = 'task'
                THEN EXISTS (SELECT 1 FROM task_message u LEFT JOIN task_read r ON r.task_id = u.task_id AND r.user_id = :me
                              WHERE u.task_id = a.subject_id AND " . TaskFeedRepository::UNREAD . ')
                ELSE a.last_event_id > COALESCE(pr.last_event_id, 0) END)';
        }

        $sql = "SELECT a.subject_type, a.subject_id, a.last_event_id, a.last_at,
                       e.type AS event_type, e.actor_id, e.payload, e.occurred_at,
                       {$unread} AS unread,
                       (a.subject_type = 'task' AND EXISTS (SELECT 1 FROM task_favorite f WHERE f.task_id = a.subject_id AND f.user_id = :me)) AS favorite
                  FROM plan_subject_activity a
                  {$lateral}
                  JOIN plan_event e ON {$event}
                  LEFT JOIN plan_read pr ON pr.user_id = :me AND pr.subject_type = a.subject_type AND pr.subject_id = a.subject_id
                 WHERE " . implode(' AND ', $where);

        if ($unreadOnly) {
            $sql = 'SELECT * FROM (' . $sql . ') x WHERE x.unread > 0';
        }

        return $this->db->fetchAllAssociative(
            $sql . ' ORDER BY last_at DESC, last_event_id DESC LIMIT ' . ($limit + 1),
            $params,
            $types,
        );
    }

    /**
     * Лічильник меню: скільки суб'єктів з новим і скільки там подій.
     * `tasks` — назва для сумісності (бейдж оболонки), рахує всі модулі.
     *
     * @return array{tasks: int, events: int, byModule: array<string, int>}
     */
    public function counts(TelegramUser $viewer): array
    {
        $tasks = $this->tasks->unreadCounts($viewer);
        $params = ['me' => (int) $viewer->getId(), 'news' => self::NEWS];
        $types = ['news' => ArrayParameterType::STRING];
        $byModule = ['task' => $tasks['tasks']];
        $subjects = $tasks['tasks'];
        $events = $tasks['events'];
        [$involved] = $this->otherInvolved($viewer, ['supply', 'warehouse'], $params);

        if ($involved !== 'FALSE') {
            foreach ($this->db->fetchAllAssociative(
                'SELECT x.subject_type, COUNT(*) AS subjects, SUM(x.n) AS events FROM (
                    SELECT a.subject_type, (' . self::newEventsSql() . ') AS n
                      FROM plan_subject_activity a
                      LEFT JOIN plan_read pr ON pr.user_id = :me AND pr.subject_type = a.subject_type AND pr.subject_id = a.subject_id
                     WHERE ' . $involved . ' AND a.last_event_id > COALESCE(pr.last_event_id, 0)
                 ) x WHERE x.n > 0 GROUP BY x.subject_type',
                $params,
                $types,
            ) as $row) {
                $module = self::moduleOf((string) $row['subject_type']);
                $byModule[$module] = ($byModule[$module] ?? 0) + (int) $row['subjects'];
                $subjects += (int) $row['subjects'];
                $events += (int) $row['events'];
            }
        }

        return ['tasks' => $subjects, 'events' => $events, 'byModule' => $byModule];
    }

    /** Прочитано до останньої події (не задача — для задач є TaskService::markRead). */
    public function markRead(TelegramUser $viewer, SubjectRef $ref): void
    {
        $this->db->executeStatement(
            'INSERT INTO plan_read (user_id, subject_type, subject_id, last_event_id, read_at)
             SELECT :me, :type, :id, MAX(e.id), LOCALTIMESTAMP(0) FROM plan_event e
              WHERE e.subject_type = :type AND e.subject_id = :id HAVING MAX(e.id) IS NOT NULL
             ON CONFLICT (user_id, subject_type, subject_id) DO UPDATE
                SET last_event_id = GREATEST(plan_read.last_event_id, EXCLUDED.last_event_id), read_at = EXCLUDED.read_at',
            ['me' => (int) $viewer->getId(), 'type' => $ref->type->value, 'id' => $ref->id],
        );
    }

    /** «Прочитати все»: задачі (task_read) + усе інше, де людина «в темі». */
    public function readAll(TelegramUser $viewer): void
    {
        $this->tasks->readAll($viewer);
        $params = ['me' => (int) $viewer->getId()];
        [$involved] = $this->otherInvolved($viewer, ['supply', 'warehouse'], $params);

        if ($involved === 'FALSE') {
            return;
        }

        $this->db->executeStatement(
            'INSERT INTO plan_read (user_id, subject_type, subject_id, last_event_id, read_at)
             SELECT :me, a.subject_type, a.subject_id, a.last_event_id, LOCALTIMESTAMP(0) FROM plan_subject_activity a WHERE ' . $involved . '
             ON CONFLICT (user_id, subject_type, subject_id) DO UPDATE
                SET last_event_id = GREATEST(plan_read.last_event_id, EXCLUDED.last_event_id), read_at = EXCLUDED.read_at
              WHERE plan_read.last_event_id < EXCLUDED.last_event_id',
            $params,
        );
    }

    /** Перебудувати plan_subject_activity з журналу. @return int скільки рядків змінено */
    public function rebuild(): int
    {
        return (int) $this->db->executeStatement(
            'INSERT INTO plan_subject_activity (subject_type, subject_id, last_event_id, last_at)
             SELECT DISTINCT ON (e.subject_type, e.subject_id) e.subject_type, e.subject_id, e.id, e.occurred_at
               FROM plan_event e WHERE e.type IN (:news) AND e.subject_type IN (:subjects)
              ORDER BY e.subject_type, e.subject_id, e.occurred_at DESC, e.id DESC
             ON CONFLICT (subject_type, subject_id) DO UPDATE
                SET last_event_id = EXCLUDED.last_event_id, last_at = EXCLUDED.last_at
              WHERE (plan_subject_activity.last_event_id, plan_subject_activity.last_at) IS DISTINCT FROM (EXCLUDED.last_event_id, EXCLUDED.last_at)',
            ['news' => self::NEWS, 'subjects' => self::subjectTypes()],
            ['news' => ArrayParameterType::STRING, 'subjects' => ArrayParameterType::STRING],
        );
    }

    /**
     * Після першого перенесення історії: усе, що вже є в журналі заявок і складу,
     * вважається прочитаним для всіх (інакше менеджер постачання побачив би «+N» на кожній
     * старій заявці). Лише тим, у кого відмітки ще немає. @return int скільки відміток додано.
     */
    public function markHistoryRead(): int
    {
        return (int) $this->db->executeStatement(
            "INSERT INTO plan_read (user_id, subject_type, subject_id, last_event_id, read_at)
             SELECT u.id, a.subject_type, a.subject_id, a.last_event_id, LOCALTIMESTAMP(0)
               FROM plan_subject_activity a CROSS JOIN telegram_user u
              WHERE a.subject_type <> 'task' AND u.access_status = 'approved' AND u.archived_at IS NULL
             ON CONFLICT (user_id, subject_type, subject_id) DO NOTHING",
        );
    }

    public function subjectsInJournal(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM (SELECT DISTINCT subject_type, subject_id FROM plan_event WHERE type IN (:news) AND subject_type IN (:subjects)) x',
            ['news' => self::NEWS, 'subjects' => self::subjectTypes()],
            ['news' => ArrayParameterType::STRING, 'subjects' => ArrayParameterType::STRING],
        );
    }

    public static function cursor(string $lastAt, int $eventId): string
    {
        return new DateTime($lastAt, new DateTimeZone('Europe/Kyiv'))->format('Y-m-d H:i:s') . '|' . $eventId;
    }

    /** @return array{0: string, 1: int} */
    private static function parseCursor(string $cursor): array
    {
        if (preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\|(\d{1,18})$/', $cursor, $m) !== 1) {
            throw new InvalidArgumentException('Bad cursor');
        }

        return [$m[1], (int) $m[2]];
    }

    /**
     * Умови видимості рядка й вираз «+N» для псевдонімів a (plan_subject_activity) і pr (plan_read).
     *
     * @param list<string> $modules
     * @param array<string, mixed> $params
     *
     * @return array{0: string, 1: string}
     */
    private function rules(TelegramUser $viewer, array $modules, array &$params): array
    {
        $allowed = $this->modulesFor($viewer);
        $modules = $modules === [] ? $allowed : array_values(array_intersect($modules, $allowed));
        $visible = [];

        if (in_array('task', $modules, true)) {
            [$taskVisible, $taskParams] = TaskVisibility::chronicle($viewer, 'tv');
            $params = [...$params, ...$taskParams];
            $visible[] = "(a.subject_type = 'task' AND EXISTS (SELECT 1 FROM task tv WHERE tv.id = a.subject_id AND {$taskVisible}))";
        }

        if (in_array('supply', $modules, true)) {
            $visible[] = $this->seesAllSupply($viewer)
                ? "a.subject_type = 'supply'"
                : "(a.subject_type = 'supply' AND " . self::supplyOwnSql() . ')';
        }

        if (in_array('warehouse', $modules, true)) {
            $visible[] = "a.subject_type IN ('wh_item', 'wh_movement', 'wh_client', 'wh_site')";
        }

        // «+N» задач — старе правило UNREAD і лише де людина бере участь.
        [$involved, $involvedParams] = TaskVisibility::involved($viewer, 'ti');
        $params = [...$params, ...$involvedParams];
        $taskUnread = "(SELECT CASE WHEN {$involved} THEN (SELECT COUNT(*) FROM task_message u WHERE u.task_id = ti.id AND " . TaskFeedRepository::UNREAD . ')
                ELSE 0 END FROM task ti LEFT JOIN task_read r ON r.task_id = ti.id AND r.user_id = :me WHERE ti.id = a.subject_id)';
        [$other] = $this->otherInvolved($viewer, ['supply', 'warehouse'], $params);
        $otherUnread = $other === 'FALSE' ? '0' : "CASE WHEN {$other} THEN (" . self::newEventsSql() . ') ELSE 0 END';

        return [
            $visible === [] ? 'FALSE' : '(' . implode(' OR ', $visible) . ')',
            "CASE WHEN a.subject_type = 'task' THEN COALESCE({$taskUnread}, 0) ELSE {$otherUnread} END",
        ];
    }

    /**
     * Де людина «в темі» заявок і складу (для «+N»), псевдонім a.
     *
     * @param list<string> $modules
     * @param array<string, mixed> $params
     *
     * @return array{0: string}
     */
    private function otherInvolved(TelegramUser $viewer, array $modules, array &$params): array
    {
        $params['me'] ??= (int) $viewer->getId();
        $parts = [];

        if (in_array('supply', $modules, true)) {
            $parts[] = $this->access->can($viewer, PlanCapability::SupplyManage)
                ? "a.subject_type = 'supply'"
                : "(a.subject_type = 'supply' AND " . self::supplyOwnSql() . ')';
        }

        if (in_array('warehouse', $modules, true) && $this->seesWarehouse($viewer) &&
            $this->access->can($viewer, PlanCapability::SupplyManage) && ! $this->access->can($viewer, PlanCapability::TasksSeeAll)) {
            $parts[] = "a.subject_type IN ('wh_item', 'wh_movement', 'wh_client', 'wh_site')";
        }

        return [$parts === [] ? 'FALSE' : '(' . implode(' OR ', $parts) . ')'];
    }

    /** Заявка «моя»: я автор або коментував. Псевдонім a, параметр :me. */
    private static function supplyOwnSql(): string
    {
        return '(EXISTS (SELECT 1 FROM supply_request sr WHERE sr.id = a.subject_id AND sr.author_id = :me)
            OR EXISTS (SELECT 1 FROM supply_comment sc WHERE sc.request_id = a.subject_id AND sc.author_id = :me))';
    }

    /** Скільки новин від інших після прочитаного (псевдоніми a, pr; параметри :me, :news). */
    private static function newEventsSql(): string
    {
        return 'SELECT COUNT(*) FROM plan_event ne WHERE ne.subject_type = a.subject_type AND ne.subject_id = a.subject_id
                   AND ne.id > COALESCE(pr.last_event_id, 0) AND ne.type IN (:news) AND ne.actor_id IS DISTINCT FROM :me';
    }

    private function seesAllSupply(TelegramUser $viewer): bool
    {
        return $this->access->can($viewer, PlanCapability::TasksSeeAll) ||
            $this->access->can($viewer, PlanCapability::SupplyManage) ||
            $this->access->can($viewer, PlanCapability::SupplyApprove);
    }

    private function seesWarehouse(TelegramUser $viewer): bool
    {
        return $this->warehouse->isEnabled() && $this->access->can($viewer, PlanCapability::WarehouseView);
    }
}
