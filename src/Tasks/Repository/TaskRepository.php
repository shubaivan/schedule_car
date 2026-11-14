<?php

namespace App\Tasks\Repository;

use App\Entity\TelegramUser;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskMember;
use App\Tasks\Enum\TaskStatus;
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
     * @param array{q?: ?string, label?: ?int, status?: list<TaskStatus>, open?: ?bool, overdue?: ?bool, member?: ?TelegramUser, department?: ?int, ids?: ?list<int>} $filters
     *
     * @return Task[]
     */
    public function search(TelegramUser $user, array $filters, int $limit = 60, string $order = 'activity'): array
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

        if (! empty($filters['department'])) {
            $qb->andWhere(sprintf(
                ':dep MEMBER OF t.departments OR EXISTS (SELECT 1 FROM %s dm JOIN dm.user du WHERE dm.task = t AND du.department = :dep)',
                TaskMember::class,
            ))->setParameter('dep', $filters['department']);
        }

        if (($filters['ids'] ?? null) !== null) {
            $qb->andWhere('t.id IN (:ids)')->setParameter('ids', $filters['ids'] ?: [0]);
        }

        // Ліміт з fetch-join колекцій зрізав би мітки — спершу id, потім повні обʼєкти.
        // Після скидання join-ів рядки не множаться, тож DISTINCT не потрібен.
        $idQuery = (clone $qb)->resetDQLPart('join')->select('t.id');

        if ($order === 'due') {
            $idQuery->addSelect('CASE WHEN t.dueAt IS NULL THEN 1 ELSE 0 END AS HIDDEN nodue')
                ->addOrderBy('nodue', SortDirection::Ascending)
                ->addOrderBy('t.dueAt', SortDirection::Ascending);
        }

        $idQuery->addOrderBy('t.lastActivityAt', SortDirection::Descending)->addOrderBy('t.id', SortDirection::Descending);

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

    /** Задачі, які людина відкривала, — колонка «Хроніка». @return int[] */
    public function openedBy(TelegramUser $user, int $limit = 40): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT r.task_id FROM task_read r JOIN task t ON t.id = r.task_id
              WHERE r.user_id = :user ORDER BY t.last_activity_at DESC LIMIT ' . $limit,
            ['user' => $user->getId()],
        ));
    }

    /**
     * «Мої повідомлення»: де людина написала останньою або її згадали.
     *
     * @return int[]
     */
    public function myMessages(TelegramUser $user, int $limit = 40): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT t.id FROM task t
              WHERE EXISTS (
                    SELECT 1 FROM task_message m WHERE m.task_id = t.id AND m.kind = 'comment' AND m.deleted_at IS NULL
                       AND m.mentions::jsonb @> to_jsonb(ARRAY[:user]::int[])
                 )
                 OR (SELECT m.author_id FROM task_message m WHERE m.task_id = t.id AND m.kind = 'comment' AND m.deleted_at IS NULL
                      ORDER BY m.id DESC LIMIT 1) = :user
              ORDER BY t.last_activity_at DESC LIMIT " . $limit,
            ['user' => $user->getId()],
        ));
    }

    /** @return array<string, int> статус => скільки (серед видимих) */
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
