<?php

namespace App\Tasks\Repository;

use App\Tasks\Entity\TaskAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TaskAttachment> */
class TaskAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskAttachment::class);
    }

    /** @return TaskAttachment[] */
    public function pending(int $limit = 20): array
    {
        return $this->findBy(['previewState' => TaskAttachment::PREVIEW_PENDING], ['id' => 'ASC'], $limit);
    }

    /**
     * Скільки файлів у задачі (без видалених повідомлень) і чи є серед них PDF/фото —
     * для значка «📎 N» на картці дошки. Один запит на всю дошку (FILTER — PostgreSQL).
     *
     * @param int[] $taskIds
     *
     * @return array<int, array{count: int, pdf: int, images: int}>
     */
    public function statsByTask(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT m.task_id, COUNT(*) AS n,
                    COUNT(*) FILTER (WHERE a.kind = :pdf) AS pdf,
                    COUNT(*) FILTER (WHERE a.kind = :image) AS images
               FROM task_attachment a
               JOIN task_message m ON m.id = a.message_id
              WHERE m.task_id IN (:ids) AND m.deleted_at IS NULL
              GROUP BY m.task_id',
            ['ids' => $taskIds, 'pdf' => TaskAttachment::KIND_PDF, 'image' => TaskAttachment::KIND_IMAGE],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $stats = [];

        foreach ($rows as $row) {
            $stats[(int) $row['task_id']] = ['count' => (int) $row['n'], 'pdf' => (int) $row['pdf'], 'images' => (int) $row['images']];
        }

        return $stats;
    }
}
