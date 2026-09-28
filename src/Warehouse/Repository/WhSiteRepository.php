<?php

namespace App\Warehouse\Repository;

use App\Warehouse\Entity\WhSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WhSite> */
class WhSiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhSite::class);
    }

    /** @return WhSite[] Склади першими, далі об'єкти за назвою. */
    public function listed(bool $onlyActive = true): array
    {
        $sites = $this->findBy($onlyActive ? ['active' => true] : [], ['name' => 'ASC']);

        usort($sites, static fn (WhSite $a, WhSite $b) => [! $a->isWarehouse(), $a->getName()] <=> [! $b->isWarehouse(), $b->getName()]);

        return $sites;
    }
}
