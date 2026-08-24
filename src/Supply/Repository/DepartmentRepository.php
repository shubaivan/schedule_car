<?php

namespace App\Supply\Repository;

use App\Supply\Entity\Department;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Department>
 */
class DepartmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Department::class);
    }

    /** Порівняння без огляду на регістр: «Цех №1» і «цех №1» — той самий підрозділ. */
    public function findOneByName(string $name): ?Department
    {
        return $this->createQueryBuilder('d')
            ->andWhere('LOWER(d.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return Department[] */
    public function findActive(): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.active = true')
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
