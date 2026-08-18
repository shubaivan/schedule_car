<?php

namespace App\Repository;

use App\Entity\Car;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Service\KyivTime;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledSet>
 */
class ScheduledSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledSet::class);
    }

    public function countOfSetByParams(int $carId, int $year, int $month, int $day, TelegramUser $user)
    {
        $qb = $this->createQueryBuilder('ss');
        $qb->select('COUNT(ss.id)');

        $qb->join('ss.car', 'car');
        $qb->andWhere('car.id = :car_Id')->setParameter('car_Id', $carId);
        $qb->andWhere('ss.year = :year')->setParameter('year', $year);
        $qb->andWhere('ss.month = :month')->setParameter('month', $month);
        $qb->andWhere('ss.day = :day')->setParameter('day', $day);
        $qb->andWhere('ss.telegramUserId = :user')->setParameter('user', $user);
        $qb->andWhere('ss.scheduledAt >= :now');
        $qb->setParameter('now', KyivTime::now());

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return ScheduledSet[]
     */
    /**
     * Розклад на проміжок часу — спільна картина для всіх.
     *
     * @return ScheduledSet[]
     */
    public function findBetween(DateTime $from, DateTime $to): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.car', 'c')->addSelect('c')
            ->leftJoin('s.telegramUserId', 'u')->addSelect('u')
            ->andWhere('s.scheduledAt >= :from')->setParameter('from', $from)
            ->andWhere('s.scheduledAt < :to')->setParameter('to', $to)
            ->orderBy('s.scheduledAt', 'ASC')
            ->addOrderBy('c.carNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Майбутні поїздки однієї машини — те, що бачить її водій.
     *
     * @return ScheduledSet[]
     */
    public function findUpcomingByCar(Car $car, DateTime $since): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.telegramUserId', 'u')->addSelect('u')
            ->andWhere('s.car = :car')->setParameter('car', $car)
            ->andWhere('s.scheduledAt >= :since')->setParameter('since', $since)
            ->orderBy('s.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Майбутні бронювання людини — «мої поїздки» заявника.
     *
     * @return ScheduledSet[]
     */
    public function findUpcomingByUser(TelegramUser $user, DateTime $since): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.car', 'c')->addSelect('c')
            ->andWhere('s.telegramUserId = :user')->setParameter('user', $user)
            ->andWhere('s.scheduledAt >= :since')->setParameter('since', $since)
            ->orderBy('s.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
