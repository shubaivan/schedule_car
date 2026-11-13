<?php

namespace App\Tasks\Repository;

use App\Tasks\Entity\TaskAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
