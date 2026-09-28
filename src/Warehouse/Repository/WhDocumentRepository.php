<?php

namespace App\Warehouse\Repository;

use App\Warehouse\Entity\WhDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WhDocument> */
class WhDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhDocument::class);
    }

    /** @return WhDocument[] Документи, копія яких ще не поїхала на Google Диск. */
    public function findNotMirrored(int $limit): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.driveFileId IS NULL')
            ->orderBy('d.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
