<?php

namespace App\Warehouse\Repository;

use App\Warehouse\Entity\WhCategory;
use App\Warehouse\Enum\CategoryScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WhCategory> */
class WhCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhCategory::class);
    }

    /** @return WhCategory[] */
    public function of(CategoryScope $scope, bool $onlyActive = true): array
    {
        return $this->findBy(
            $onlyActive ? ['scope' => $scope, 'active' => true] : ['scope' => $scope],
            ['position' => 'ASC', 'name' => 'ASC'],
        );
    }
}
