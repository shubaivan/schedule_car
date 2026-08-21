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

    /**
     * Водії, розкладені по машинах: id машини → її люди.
     *
     * Одним запитом на весь розклад: інакше кожна машина в календарі тягла б
     * свій запит за водієм, а тиждень великого парку — це десятки машин.
     *
     * @return array<int, TelegramUser[]>
     */
    public function driversByCar(): array
    {
        $links = $this->createQueryBuilder('cd')
            ->leftJoin('cd.driver', 'd')->addSelect('d')
            ->leftJoin('cd.car', 'c')->addSelect('c')
            ->getQuery()
            ->getResult();

        $byCar = [];

        foreach ($links as $link) {
            $byCar[(int) $link->getCar()->getId()][] = $link->getDriver();
        }

        return $byCar;
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
