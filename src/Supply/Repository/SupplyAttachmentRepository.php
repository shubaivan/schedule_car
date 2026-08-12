<?php

namespace App\Supply\Repository;

use App\Supply\Entity\SupplyAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupplyAttachment>
 */
class SupplyAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplyAttachment::class);
    }

    /**
     * Файли, які ще не поїхали в Google Drive. Черга для крона: копію робимо
     * окремим кроком, щоб проблеми з Google не ламали завантаження в CRM.
     *
     * @return SupplyAttachment[]
     */
    public function findNotMirrored(int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.driveFileId IS NULL')
            ->orderBy('a.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
