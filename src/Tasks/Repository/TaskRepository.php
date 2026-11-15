<?php

namespace App\Tasks\Repository;

use App\Entity\TelegramUser;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskMember;
use App\Tasks\Enum\TaskStatus;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * Вибірки задач з урахуванням видимості: директор і адмін бачать усе,
 * решта — задачі, де вони автор або учасник («Нова заявка» видна лише
 * тим, кого в ній позначили — відповідь клієнта 13.11.2026).
 *
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    /** Фільтр «Без відділу» у списку задач і «Контролі по відділах». */
    public const int DEPARTMENT_NONE = -1;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    public function visibleTo(TelegramUser $user, string $alias = 't'): QueryBuilder
    {
        $qb = $this->createQueryBuilder($alias)
            ->leftJoin($alias . '.labels', 'l')->addSelect('l')
            ->leftJoin($alias . '.creator', 'c')->addSelect('c');

        if (! $user->seesAllTasks()) {
            $qb->andWhere(sprintf(
                '%1$s.creator = :viewer OR EXISTS (SELECT 1 FROM %2$s vm WHERE vm.task = %1$s AND vm.user = :viewer)',
                $alias,
                TaskMember::class,
            ))->setParameter('viewer', $user);
        }

        return $qb;
    }

    /**
     * @param array{q?: ?string, label?: ?int, status?: list<TaskStatus>, open?: ?bool, overdue?: ?bool, stale?: ?int, member?: ?TelegramUser, department?: ?int, taggedDepartment?: ?int, ids?: ?list<int>} $filters
     *
     * @return Task[]
     */
    public function search(TelegramUser $user, array $filters, int $limit = 60, string $order = 'activity'): array
    {
        $qb = $this->filtered($user, $filters);

        // Ліміт з fetch-join колекцій зрізав би мітки — спершу id, потім повні обʼєкти.
        // Після скидання join-ів рядки не множаться, тож DISTINCT не потрібен.
        $idQuery = (clone $qb)->resetDQLPart('join')->select('t.id');

        if ($order === 'due') {
            $idQuery->addSelect('CASE WHEN t.dueAt IS NULL THEN 1 ELSE 0 END AS HIDDEN nodue')
                ->addOrderBy('nodue', SortDirection::Ascending)
                ->addOrderBy('t.dueAt', SortDirection::Ascending);
        }

        // «Без руху» — спершу ті, що стоять найдовше.
        $idQuery->addOrderBy('t.lastActivityAt', $order === 'stale' ? SortDirection::Ascending : SortDirection::Descending)
            ->addOrderBy('t.id', SortDirection::Descending);

        $ids = array_map(
            static fn (array $row) => (int) $row['id'],
            $idQuery->setMaxResults($limit)->getQuery()->getArrayResult(),
        );

        if ($ids === []) {
            return [];
        }

        $tasks = $this->createQueryBuilder('t')
            ->leftJoin('t.labels', 'l')->addSelect('l')
            ->leftJoin('t.creator', 'c')->addSelect('c')
            ->leftJoin('t.members', 'm')->addSelect('m')
            ->leftJoin('m.user', 'mu')->addSelect('mu')
            ->leftJoin('mu.department', 'md')->addSelect('md')
            ->leftJoin('t.departments', 'd')->addSelect('d')
            ->andWhere('t.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getResult();

        $position = array_flip($ids);
        usort($tasks, static fn (Task $a, Task $b) => $position[$a->getId()] <=> $position[$b->getId()]);

        return $tasks;
    }

    /**
     * Скільки задач під тими самими фільтрами, що й search() — для «показано N з M».
     *
     * @param array{q?: ?string, label?: ?int, status?: list<TaskStatus>, open?: ?bool, overdue?: ?bool, stale?: ?int, member?: ?TelegramUser, department?: ?int, taggedDepartment?: ?int, ids?: ?list<int>} $filters
     */
    public function countMatching(TelegramUser $user, array $filters): int
    {
        return (int) $this->filtered($user, $filters)->resetDQLPart('join')->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * «Без руху» — від цього моменту назад остання активність (повідомлення, статус, правка).
     */
    public static function staleBefore(int $days): DateTime
    {
        return Task::now()->modify(sprintf('-%d days', $days));
    }

    /**
     * Фільтри списку задач. department = id відділу (задача позначена відділом або в ній
     * є людина з цього відділу — «мій відділ», як у пошуку); taggedDepartment — лише
     * позначений у задачі відділ, DEPARTMENT_NONE — задача без позначеного відділу.
     *
     * @param array{q?: ?string, label?: ?int, status?: list<TaskStatus>, open?: ?bool, overdue?: ?bool, stale?: ?int, member?: ?TelegramUser, department?: ?int, taggedDepartment?: ?int, ids?: ?list<int>} $filters
     */
    private function filtered(TelegramUser $user, array $filters): QueryBuilder
    {
        $qb = $this->visibleTo($user);

        if (($filters['q'] ?? '') !== '' && $filters['q'] !== null) {
            $q = trim($filters['q']);
            $expr = 'LOWER(t.title) LIKE :q OR LOWER(COALESCE(t.client, \'\')) LIKE :q OR LOWER(COALESCE(t.description, \'\')) LIKE :q';

            // «№ 12», «#0012», «12» — пошук за номером задачі.
            if (preg_match('/^[№#\s]*(\d{1,9})$/u', $q, $m) === 1) {
                $expr .= ' OR t.id = :qid';
                $qb->setParameter('qid', (int) $m[1]);
            }

            $qb->andWhere($expr)->setParameter('q', '%' . mb_strtolower($q) . '%');
        }

        if (! empty($filters['label'])) {
            $qb->andWhere(':label MEMBER OF t.labels')->setParameter('label', $filters['label']);
        }

        if (! empty($filters['status'])) {
            $qb->andWhere('t.status IN (:statuses)')->setParameter('statuses', $filters['status']);
        }

        if (($filters['open'] ?? null) === true) {
            $qb->andWhere('t.status NOT IN (:closed)')->setParameter('closed', [TaskStatus::Done, TaskStatus::Rejected]);
        }

        if (($filters['overdue'] ?? null) === true) {
            $qb->andWhere('t.dueAt IS NOT NULL AND t.dueAt < :now')
                ->andWhere('t.status NOT IN (:closedO)')
                ->setParameter('closedO', [TaskStatus::Done, TaskStatus::Rejected])
                ->setParameter('now', Task::now());
        }

        if (($filters['member'] ?? null) !== null) {
            $qb->andWhere(sprintf('t.creator = :me OR EXISTS (SELECT 1 FROM %s mm WHERE mm.task = t AND mm.user = :me)', TaskMember::class))
                ->setParameter('me', $filters['member']);
        }

        if (($filters['stale'] ?? null) !== null) {
            $qb->andWhere('t.lastActivityAt < :staleBefore')
                ->andWhere('t.status NOT IN (:closedS)')
                ->setParameter('closedS', [TaskStatus::Done, TaskStatus::Rejected])
                ->setParameter('staleBefore', self::staleBefore((int) $filters['stale']));
        }

        // «Контроль по відділах» і список з нього: лише відділи, позначені в самій задачі
        // ([N. Відділ …]), — без відділів учасників (рішення Івана 15.11: інакше «Дирекція»
        // роздувається через директора-учасника).
        if (($filters['taggedDepartment'] ?? null) === self::DEPARTMENT_NONE) {
            $qb->andWhere('t.departments IS EMPTY');
        } elseif (! empty($filters['taggedDepartment'])) {
            $qb->andWhere(':tdep MEMBER OF t.departments')->setParameter('tdep', $filters['taggedDepartment']);
        }

        if (! empty($filters['department'])) {
            $qb->andWhere(sprintf(
                ':dep MEMBER OF t.departments OR EXISTS (SELECT 1 FROM %s dm JOIN dm.user du WHERE dm.task = t AND du.department = :dep)',
                TaskMember::class,
            ))->setParameter('dep', $filters['department']);
        }

        if (($filters['ids'] ?? null) !== null) {
            $qb->andWhere('t.id IN (:ids)')->setParameter('ids', $filters['ids'] ?: [0]);
        }

        return $qb;
    }

    /**
     * Нові події від інших людей по кожній задачі — те саме правило, що й «+N» у Хроніці
     * (TaskFeedRepository::UNREAD, TaskVisibility::involved): на задачах, де людина
     * не бере участі (директор, адмін бачать усе), «+N» немає.
     *
     * @param int[] $taskIds
     *
     * @return array<int, int>
     */
    public function unreadCounts(TelegramUser $user, array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        // Лише задачі, де людина бере участь (як у Хроніці): директор бачить увесь завод,
        // але «+N» на чужих задачах, яких він ніколи не відкривав, — шум, і з Хронікою не зійдеться.
        [$involved, $params] = TaskVisibility::involved($user);

        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT u.task_id, COUNT(*) FROM task_message u
               JOIN task t ON t.id = u.task_id
               LEFT JOIN task_read r ON r.task_id = u.task_id AND r.user_id = :me
              WHERE u.task_id IN (:ids) AND ' . $involved . ' AND ' . TaskFeedRepository::UNREAD . '
              GROUP BY u.task_id',
            [...$params, 'me' => $user->getId(), 'ids' => $taskIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_map('intval', $rows);
    }

    /**
     * «Мої повідомлення» (Олександр, 15.11.2026): «я створив, я написав» або «до мене
     * звернулись» (@відмітка) — найсвіжіша активність зверху. Видимість — у search().
     *
     * @return int[]
     */
    public function myMessages(TelegramUser $user, int $limit = 40): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT t.id FROM task t
              WHERE t.creator_id = :user
                 OR EXISTS (
                    SELECT 1 FROM task_message m WHERE m.task_id = t.id AND m.kind = 'comment' AND m.deleted_at IS NULL
                       AND (m.author_id = :user OR m.mentions::jsonb @> to_jsonb(ARRAY[:user]::int[]))
                 )
              ORDER BY t.last_activity_at DESC LIMIT " . $limit,
            ['user' => $user->getId()],
        ));
    }

    /** @return array<string, int> статус => скільки (серед видимих) */
    /**
     * Лічильники «Огляду» одним запитом без ORM (раніше — три вибірки по 500 задач
     * з усіма учасниками лише заради count()).
     *
     * @return array{mine: int, overdue: int, open: int, byStatus: array<string, int>}
     */
    public function dashboardStats(TelegramUser $user): array
    {
        [$visible, $params] = TaskVisibility::sql($user, 't');
        $closed = "'" . TaskStatus::Done->value . "', '" . TaskStatus::Rejected->value . "'";

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT t.status,
                    COUNT(*) AS n,
                    COUNT(*) FILTER (WHERE t.status NOT IN (' . $closed . ') AND (t.creator_id = :me
                        OR EXISTS (SELECT 1 FROM task_member mm WHERE mm.task_id = t.id AND mm.user_id = :me))) AS mine,
                    COUNT(*) FILTER (WHERE t.due_at IS NOT NULL AND t.due_at < CAST(:now AS timestamp) AND t.status NOT IN (' . $closed . ')) AS overdue
               FROM task t
              WHERE ' . $visible . '
              GROUP BY t.status',
            [...$params, 'me' => (int) $user->getId(), 'now' => Task::now()->format('Y-m-d H:i:s')],
        );

        $out = ['mine' => 0, 'overdue' => 0, 'open' => 0, 'byStatus' => []];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $out['byStatus'][$status] = (int) $row['n'];
            $out['mine'] += (int) $row['mine'];
            $out['overdue'] += (int) $row['overdue'];

            if (! (TaskStatus::tryFrom($status)?->isClosed() ?? false)) {
                $out['open'] += (int) $row['n'];
            }
        }

        return $out;
    }

    /**
     * «Контроль по відділах»: відкриті, прострочені й без руху N днів — по кожному відділу
     * серед задач, які людина бачить. Відділ задачі — лише позначений у самій задачі
     * (task_department), як фільтр taggedDepartment у списку, тож число й список збігаються.
     * Задача в кількох відділах рахується в кожному — рядки не складаються в «усього»,
     * для цього окремо total (без повторів).
     *
     * @return array{departments: array<int, array{open: int, overdue: int, stale: int}>, none: array{open: int, overdue: int, stale: int}, total: array{open: int, overdue: int, stale: int}}
     */
    public function departmentControl(TelegramUser $user, int $staleDays): array
    {
        [$visible, $params] = TaskVisibility::sql($user, 't');
        $params = [
            ...$params,
            'now' => Task::now()->format('Y-m-d H:i:s'),
            'stale' => self::staleBefore($staleDays)->format('Y-m-d H:i:s'),
            'closed' => [TaskStatus::Done->value, TaskStatus::Rejected->value],
        ];
        $types = ['closed' => ArrayParameterType::STRING];

        // Один прохід: відкриті видимі задачі → пари (задача, відділ) без повторів → підсумки.
        // department_id NULL — задача без жодного позначеного відділу.
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'WITH v AS (
                 SELECT t.id, (t.due_at IS NOT NULL AND t.due_at < CAST(:now AS timestamp)) AS overdue,
                        (t.last_activity_at < CAST(:stale AS timestamp)) AS stale
                   FROM task t
                  WHERE t.status NOT IN (:closed) AND ' . $visible . '
             ), td AS (
                 SELECT d.task_id, d.department_id FROM task_department d JOIN v ON v.id = d.task_id
             ), pairs AS (
                 SELECT v.id, td.department_id, v.overdue, v.stale FROM v LEFT JOIN td ON td.task_id = v.id
             )
             SELECT department_id, COUNT(*) AS open, COUNT(*) FILTER (WHERE overdue) AS overdue, COUNT(*) FILTER (WHERE stale) AS stale, FALSE AS total
               FROM pairs GROUP BY department_id
             UNION ALL
             SELECT NULL, COUNT(*), COUNT(*) FILTER (WHERE overdue), COUNT(*) FILTER (WHERE stale), TRUE FROM v',
            $params,
            $types,
        );

        $zero = ['open' => 0, 'overdue' => 0, 'stale' => 0];
        $out = ['departments' => [], 'none' => $zero, 'total' => $zero];

        foreach ($rows as $row) {
            $counts = ['open' => (int) $row['open'], 'overdue' => (int) $row['overdue'], 'stale' => (int) $row['stale']];

            if ((bool) $row['total']) {
                $out['total'] = $counts;
            } elseif ($row['department_id'] === null) {
                $out['none'] = $counts;
            } else {
                $out['departments'][(int) $row['department_id']] = $counts;
            }
        }

        return $out;
    }

    public function countByStatus(TelegramUser $user): array
    {
        $rows = $this->visibleTo($user)
            ->select('t.status AS status, COUNT(DISTINCT t.id) AS n')
            ->resetDQLPart('join')
            ->groupBy('t.status')
            ->getQuery()->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $status = $row['status'] instanceof TaskStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['n'];
        }

        return $counts;
    }
}
