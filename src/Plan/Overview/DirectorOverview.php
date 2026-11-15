<?php

namespace App\Plan\Overview;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanAccess;
use App\Plan\Access\PlanCapability;
use App\Repository\TelegramUserRepository;
use App\Service\FleetSection;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Repository\DepartmentRepository;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\RequestPresenter;
use App\Supply\Service\SupplyReports;
use App\Tasks\Entity\Task;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Repository\TaskAttachmentRepository;
use App\Tasks\Repository\TaskMessageRepository;
use App\Tasks\Repository\TaskRepository;
use App\Tasks\Repository\TaskVisibility;
use App\Tasks\Service\TaskPresenter;
use App\Warehouse\Service\LowStock;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * «Огляд» v2 — екран директора за зразком ПланФікса: рядок великих чисел, під ним
 * «Чекають моєї відповіді» (мене відмітили в чаті задачі, я ще не відповів),
 * заявки на моєму рішенні з кнопками просто в рядку, «Контроль по відділах»,
 * постачання за місяць, автопарк по бронях і склад. Одна відповідь на весь екран.
 *
 * Кожне число рахується тим самим правилом, що й список, куди воно веде
 * (/tasks/list, дошка заявок, склад), і в межах того, що людина бачить:
 * директор і адмін — увесь завод, решта — свої задачі; гроші постачання —
 * лише тим, хто веде або погоджує закупівлі; склад — тим, хто його веде.
 */
final class DirectorOverview
{
    public const int STALE_DAYS = 3;
    private const int DECISIONS_LIMIT = 10;
    private const int REPLIES_LIMIT = 10;
    private const int MINE_LIMIT = 6;
    private const int LOW_LIMIT = 6;

    public function __construct(
        private readonly Connection $db,
        private readonly PlanAccess $access,
        private readonly PlanOverview $overview,
        private readonly TaskRepository $tasks,
        private readonly TaskPresenter $taskPresenter,
        private readonly TelegramUserRepository $users,
        private readonly TaskMessageRepository $messages,
        private readonly TaskAttachmentRepository $attachments,
        private readonly SupplyRequestRepository $requests,
        private readonly RequestPresenter $requestPresenter,
        private readonly SupplyReports $reports,
        private readonly DepartmentRepository $departments,
        private readonly FleetSection $fleet,
        private readonly LowStock $lowStock,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(TelegramUser $me): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'));
        $stats = $this->tasks->dashboardStats($me);
        $decisions = $this->decisions($me);
        $replies = $this->replies($me);
        $supply = $this->supply($me, $now);
        $fleet = $this->fleet($now);
        $warehouse = $this->warehouse($me);
        $control = $this->control($me);

        return [
            'generatedAt' => $now->format(DATE_ATOM),
            'scope' => [
                'all' => $me->seesAllTasks(),
                'department' => $me->getDepartment()?->getName(),
                'decideSupply' => $supply['approve'],
            ],
            'kpi' => [
                'overdueTasks' => $stats['overdue'],
                'openTasks' => $stats['open'],
                'mineTasks' => $stats['mine'],
                'staleTasks' => $control['total']['stale'],
                'replies' => $replies['total'],
                'decisions' => $decisions['total'],
                'supplyOverdue' => $supply['counts']['overdue'],
                'fleet' => ! $fleet['enabled'] ? null : ['busy' => $fleet['busyToday'], 'free' => $fleet['freeToday']],
                'shipmentsToday' => $warehouse['shipmentsToday'] ?? null,
            ],
            'replies' => $replies,
            'decisions' => $decisions,
            'control' => $control,
            'supply' => $supply,
            'fleet' => $fleet,
            'warehouse' => $warehouse,
            'mine' => $this->mine($me),
        ];
    }

    /**
     * «Чекають моєї відповіді»: коментарі в задачах, де мене відмітили (@Ім'я), а я
     * після них у цій задачі ще нічого не написав. Рішення директора в задачах немає
     * (15.11.2026) — питання ставлять відміткою в чаті, відповідь закриває рядок.
     * Найстаріші — першими; закриті задачі й видалені повідомлення не рахуються.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function replies(TelegramUser $me): array
    {
        [$visible, $params] = TaskVisibility::sql($me, 't');
        $where = "m.kind = 'comment' AND m.deleted_at IS NULL
              AND m.mentions::jsonb @> to_jsonb(ARRAY[:me]::int[])
              AND m.author_id IS DISTINCT FROM :me
              AND t.status NOT IN (:closed)
              AND " . $visible . "
              AND NOT EXISTS (SELECT 1 FROM task_message a WHERE a.task_id = m.task_id AND a.id > m.id
                                 AND a.author_id = :me AND a.kind = 'comment' AND a.deleted_at IS NULL)";
        $params = [...$params, 'me' => (int) $me->getId(), 'closed' => [TaskStatus::Done->value, TaskStatus::Rejected->value]];
        $types = ['closed' => ArrayParameterType::STRING];

        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM task_message m JOIN task t ON t.id = m.task_id WHERE ' . $where, $params, $types);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT m.id, m.task_id, m.author_id, m.text, m.created_at, t.title, t.client,
                    (m.id > COALESCE(r.last_message_id, 0)) AS unread
               FROM task_message m
               JOIN task t ON t.id = m.task_id
               LEFT JOIN task_read r ON r.task_id = m.task_id AND r.user_id = :me
              WHERE ' . $where . '
              ORDER BY m.id ASC
              LIMIT ' . self::REPLIES_LIMIT,
            $params,
            $types,
        );

        $tasks = [];

        foreach ($this->tasks->findBy(['id' => array_values(array_unique(array_map(static fn (array $r) => (int) $r['task_id'], $rows)))]) as $task) {
            $tasks[(int) $task->getId()] = $task;
        }

        $authors = [];

        foreach ($this->users->findBy(['id' => array_values(array_unique(array_filter(array_map(static fn (array $r) => (int) $r['author_id'], $rows))))]) as $user) {
            $authors[(int) $user->getId()] = $user;
        }

        $kyiv = new DateTimeZone('Europe/Kyiv');
        $items = [];

        foreach ($rows as $row) {
            $task = $tasks[(int) $row['task_id']] ?? null;
            $author = $authors[(int) $row['author_id']] ?? null;
            $text = trim(preg_replace('/\s+/u', ' ', (string) $row['text']) ?? '');

            $items[] = [
                'messageId' => (int) $row['id'],
                'taskId' => (int) $row['task_id'],
                'number' => $task?->number(),
                'title' => (string) $row['title'],
                'client' => $row['client'],
                'excerpt' => mb_strlen($text) > 180 ? mb_substr($text, 0, 179) . '…' : $text,
                'at' => new DateTimeImmutable((string) $row['created_at'], $kyiv)->format(DATE_ATOM),
                'author' => $author !== null ? $this->taskPresenter->person($author) : null,
                'unread' => (bool) $row['unread'],
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Заявки постачання, що чекають рішення тих, хто погоджує оплату; у кожному рядку —
     * лише ті рішення, які ця людина справді може ухвалити. Задач тут немає: рішення
     * директора в задачах прибрано (15.11.2026), їхнє місце — «Чекають моєї відповіді».
     *
     * @return array{items: list<array<string, mixed>>, total: int, supply: int}
     */
    private function decisions(TelegramUser $me): array
    {
        $items = [];
        $supplyTotal = 0;

        if ($this->access->can($me, PlanCapability::SupplyApprove)) {
            [$requests, $supplyTotal] = $this->awaitingSupply();

            foreach ($requests as $request) {
                $items[] = $this->supplyDecision($me, $request);
            }
        }

        // Спершу прострочені й з найближчим терміном, далі — що довше чекає.
        usort($items, static fn (array $a, array $b) => [! $a['overdue'], $a['due'] ?? '9999', $a['since']] <=> [! $b['overdue'], $b['due'] ?? '9999', $b['since']]);

        return [
            'items' => array_slice($items, 0, self::DECISIONS_LIMIT),
            'total' => $supplyTotal,
            'supply' => $supplyTotal,
        ];
    }

    /**
     * Заявки на рішенні в порядку терміновості (прострочені, далі за терміном, далі
     * найстаріші) — а не як на дошці; закупівлі підтягнуто одним запитом (сума в рядку).
     *
     * @return array{0: list<SupplyRequest>, 1: int}
     */
    private function awaitingSupply(): array
    {
        $statuses = [SupplyStatus::Approval->value, SupplyStatus::Waiting->value];
        $ids = array_map('intval', $this->db->fetchFirstColumn(
            'SELECT id FROM supply_request WHERE status IN (:st)
              ORDER BY (need_by IS NOT NULL AND need_by < CAST(:today AS date)) DESC, need_by ASC NULLS LAST, created_at ASC, id ASC
              LIMIT ' . self::DECISIONS_LIMIT,
            ['st' => $statuses, 'today' => PlanOverview::today()],
            ['st' => ArrayParameterType::STRING],
        ));
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM supply_request WHERE status IN (:st)', ['st' => $statuses], ['st' => ArrayParameterType::STRING]);

        if ($ids === []) {
            return [[], $total];
        }

        /** @var list<SupplyRequest> $requests */
        $requests = $this->requests->createQueryBuilder('r')
            ->leftJoin('r.purchases', 'p')->addSelect('p')
            ->leftJoin('r.author', 'a')->addSelect('a')
            ->leftJoin('r.department', 'd')->addSelect('d')
            ->andWhere('r.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getResult();
        $position = array_flip($ids);
        usort($requests, static fn (SupplyRequest $x, SupplyRequest $y) => $position[(int) $x->getId()] <=> $position[(int) $y->getId()]);

        return [$requests, $total];
    }

    /** @return array<string, mixed> */
    private function supplyDecision(TelegramUser $me, SupplyRequest $request): array
    {
        $from = $request->getStatus();
        $actions = [];
        // «В списку очікування» напряму не погоджується (SupplyStatus::allowedTransitions):
        // повертаємо на затвердження — далі той самий «Погодити».
        $labels = [SupplyStatus::Approved->value => 'Погодити', SupplyStatus::Approval->value => 'Повернути на затвердження', SupplyStatus::Rejected->value => 'Відхилити'];

        foreach ([SupplyStatus::Approved, SupplyStatus::Approval, SupplyStatus::Rejected] as $to) {
            if ($from->canTransitionTo($to) && $me->getSupplyRole()->canMoveRequest($from, $to)) {
                // Відхилення заявки — лише з причиною (ChangeStatus): заявник має знати, що далі.
                $actions[] = ['to' => $to->value, 'label' => $labels[$to->value], 'reject' => $to === SupplyStatus::Rejected, 'reasonRequired' => $to === SupplyStatus::Rejected];
            }
        }

        $item = $this->requestPresenter->listItem($request);
        $total = (float) $request->getPurchaseTotal();

        return [
            'kind' => 'supply',
            'id' => $request->getId(),
            'number' => $request->getNumber(),
            'title' => $request->getItem() . ' — ' . $request->getQuantityLabel(),
            'detail' => $item['department'],
            'status' => $from->value,
            'statusLabel' => $from->label(),
            'due' => $item['needBy'],
            'overdue' => $item['overdue'],
            'since' => $item['createdAt'],
            'person' => $item['author'],
            'amount' => $total > 0 ? $total : null,
            'actions' => $actions,
        ];
    }

    /**
     * Матриця «відділ × (відкриті, прострочені, без руху)». Директор бачить усі діючі
     * відділи (і нулі — теж відповідь), решта — лише відділи зі своїми задачами.
     *
     * @return array<string, mixed>
     */
    private function control(TelegramUser $me): array
    {
        $counts = $this->tasks->departmentControl($me, self::STALE_DAYS);
        $all = $me->seesAllTasks();
        $zero = ['open' => 0, 'overdue' => 0, 'stale' => 0];
        $rows = [];
        $known = [];

        foreach ($this->departments->findActive() as $department) {
            $id = (int) $department->getId();
            $known[$id] = true;
            $row = $counts['departments'][$id] ?? $zero;

            if ($all || $row['open'] > 0) {
                $rows[] = ['id' => $id, 'name' => $department->getName(), ...$row];
            }
        }

        // Архівний відділ, у якому ще висять відкриті задачі, — теж відхилення.
        foreach ($counts['departments'] as $id => $row) {
            if (! isset($known[$id]) && $row['open'] > 0) {
                $rows[] = ['id' => $id, 'name' => $this->departments->find($id)?->getName() ?? 'Відділ #' . $id, ...$row];
            }
        }

        return [
            'rows' => $rows,
            'none' => $counts['none'],
            'total' => $counts['total'],
            'staleDays' => self::STALE_DAYS,
        ];
    }

    /** @return array<string, mixed> */
    private function supply(TelegramUser $me, DateTimeImmutable $now): array
    {
        $approve = $this->access->can($me, PlanCapability::SupplyApprove);
        $manage = $this->access->can($me, PlanCapability::SupplyManage);
        $from = $now->modify('first day of this month')->setTime(0, 0);
        $to = $now->modify('last day of this month')->setTime(0, 0);

        return [
            'counts' => $this->overview->supplyCounts($me),
            'approve' => $approve,
            'manage' => $manage || $approve,
            // Гроші — тим, хто веде або погоджує закупівлі (звіт постачання — так само).
            'month' => $manage || $approve ? [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                ...$this->reports->spend($from, $to),
            ] : null,
            'reports' => $manage,
        ];
    }

    /**
     * Автопарк по бронях: які машини мають броні сьогодні й завтра (години). Статусів
     * «в рейсі» / «ремонт» у даних немає — лише броні; розділ вимкнено — так і кажемо.
     *
     * @return array<string, mixed>
     */
    private function fleet(DateTimeImmutable $now): array
    {
        if (! $this->fleet->isEnabled()) {
            return ['enabled' => false];
        }

        $today = $now->setTime(0, 0);
        $rows = $this->db->fetchAllAssociative(
            "SELECT c.id, c.car_number, c.model,
                    COALESCE(array_to_json(array_agg(DISTINCT s.hour ORDER BY s.hour) FILTER (WHERE s.scheduled_at < CAST(:tomorrow AS timestamp))), '[]') AS today,
                    COALESCE(array_to_json(array_agg(DISTINCT s.hour ORDER BY s.hour) FILTER (WHERE s.scheduled_at >= CAST(:tomorrow AS timestamp))), '[]') AS tomorrow
               FROM car c
               LEFT JOIN scheduled_set s ON s.car_id = c.id AND s.scheduled_at >= CAST(:today AS timestamp) AND s.scheduled_at < CAST(:after AS timestamp)
              WHERE c.active = TRUE
              GROUP BY c.id, c.car_number, c.model
              ORDER BY c.car_number",
            [
                'today' => $today->format('Y-m-d H:i:s'),
                'tomorrow' => $today->modify('+1 day')->format('Y-m-d H:i:s'),
                'after' => $today->modify('+2 days')->format('Y-m-d H:i:s'),
            ],
        );

        $cars = array_map(static fn (array $row) => [
            'id' => (int) $row['id'],
            'number' => (string) $row['car_number'],
            'model' => $row['model'],
            'today' => array_map('intval', (array) json_decode((string) $row['today'], true)),
            'tomorrow' => array_map('intval', (array) json_decode((string) $row['tomorrow'], true)),
        ], $rows);
        $busy = count(array_filter($cars, static fn (array $c) => $c['today'] !== []));

        return [
            'enabled' => true,
            'cars' => $cars,
            'busyToday' => $busy,
            'freeToday' => count($cars) - $busy,
            'busyTomorrow' => count(array_filter($cars, static fn (array $c) => $c['tomorrow'] !== [])),
        ];
    }

    /** @return array<string, mixed>|null */
    private function warehouse(TelegramUser $me): ?array
    {
        $base = $this->overview->warehouse($me);

        if ($base === null) {
            return null;
        }

        $low = $this->lowStock->items();

        return [...$base, 'lowCount' => count($low), 'low' => array_slice($low, 0, self::LOW_LIMIT)];
    }

    /** @return list<array<string, mixed>> */
    private function mine(TelegramUser $me): array
    {
        $tasks = $this->tasks->search($me, ['member' => $me, 'open' => true], self::MINE_LIMIT, 'due');
        $ids = array_map(static fn (Task $t) => (int) $t->getId(), $tasks);
        $unread = $this->tasks->unreadCounts($me, $ids);
        $last = $this->messages->lastComments($ids);
        $files = $this->attachments->statsByTask($ids);

        return array_map(fn (Task $t) => $this->taskPresenter->listItem($t, $unread, $last, $files), $tasks);
    }
}
