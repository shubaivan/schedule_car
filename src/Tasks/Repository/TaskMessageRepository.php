<?php

namespace App\Tasks\Repository;

use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskMessage;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/** @extends ServiceEntityRepository<TaskMessage> */
class TaskMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskMessage::class);
    }

    /** @return TaskMessage[] */
    public function forTask(Task $task, int $afterId = 0): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.attachments', 'a')->addSelect('a')
            ->leftJoin('m.author', 'u')->addSelect('u')
            ->andWhere('m.task = :task')
            ->andWhere('m.id > :after')
            ->setParameter('task', $task)
            ->setParameter('after', $afterId)
            ->orderBy('m.id', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Старі повідомлення, які змінились (правка, видалення, закріплення) — для опитування чату.
     *
     * @return TaskMessage[]
     */
    public function changedSince(Task $task, DateTimeInterface $since, int $upToId): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.attachments', 'a')->addSelect('a')
            ->leftJoin('m.author', 'u')->addSelect('u')
            ->andWhere('m.task = :task')
            ->andWhere('m.id <= :upTo')
            ->andWhere('m.changedAt >= :since')
            ->setParameter('task', $task)
            ->setParameter('upTo', $upToId)
            ->setParameter('since', $since, Types::DATETIME_MUTABLE)
            ->orderBy('m.id', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function lastId(Task $task): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('MAX(m.id)')
            ->andWhere('m.task = :task')
            ->setParameter('task', $task)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Останній коментар кожної задачі — для рядка «хто що написав» у списках.
     *
     * @param int[] $taskIds
     *
     * @return array<int, TaskMessage>
     */
    public function lastComments(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT MAX(id) FROM task_message WHERE kind = 'comment' AND deleted_at IS NULL AND task_id IN (?) GROUP BY task_id",
            [$taskIds],
            [ArrayParameterType::INTEGER],
        );

        $result = [];

        foreach ($this->createQueryBuilder('m')
            ->leftJoin('m.attachments', 'a')->addSelect('a')
            ->leftJoin('m.author', 'u')->addSelect('u')
            ->andWhere('m.id IN (:ids)')->setParameter('ids', $ids ?: [0])
            ->getQuery()->getResult() as $message) {
            $result[$message->getTask()->getId()] = $message;
        }

        return $result;
    }
}
