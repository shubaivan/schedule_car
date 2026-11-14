<?php

namespace App\Repository;

use App\Entity\DriverPhone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<DriverPhone>
 */
class DriverPhoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DriverPhone::class);
    }

    public function findByTail(string $tail): ?DriverPhone
    {
        return $tail === '' ? null : $this->findOneBy(['tail' => $tail]);
    }

    /** @return DriverPhone[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.car', 'c')->addSelect('c')
            ->orderBy('d.name', SortDirection::Ascending)
            ->addOrderBy('d.phone', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
