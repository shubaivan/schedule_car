<?php

namespace App\Repository;

use App\Entity\CarDriver;
use App\Entity\TelegramUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CarDriver>
 */
class CarDriverRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CarDriver::class);
    }

    public function findOneByDriver(TelegramUser $driver): ?CarDriver
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.driver = :driver')
            ->setParameter('driver', $driver)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
