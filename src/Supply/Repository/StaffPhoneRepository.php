<?php

namespace App\Supply\Repository;

use App\Supply\Entity\StaffPhone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<StaffPhone>
 */
class StaffPhoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffPhone::class);
    }

    public function findByTail(string $tail): ?StaffPhone
    {
        return $tail === '' ? null : $this->findOneBy(['tail' => $tail]);
    }

    /** @return StaffPhone[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.role', SortDirection::Ascending)
            ->addOrderBy('s.name', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
