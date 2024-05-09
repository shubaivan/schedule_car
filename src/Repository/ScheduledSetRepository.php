<?php

namespace App\Repository;

use App\Entity\Car;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Service\ScheduleCarService;
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

    /**
     * @param int $carId
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int|null $hour
     * @param TelegramUser|null $user
     * @return ScheduledSet[]
     */
    public function getByParams(int $carId, int $year, int $month, int $day, ?int $hour, ?TelegramUser $user): array
    {
        $qb = $this->createQueryBuilder('ss');
        $qb->join('ss.car', 'car');
        $qb->andWhere('car.id = :car_Id')->setParameter('car_Id', $carId);

        $qb->andWhere('ss.year = :year')->setParameter('year', $year);
        $qb->andWhere('ss.month = :month')->setParameter('month', $month);
        $qb->andWhere('ss.day = :day')->setParameter('day', $day);

        $qb->andWhere('ss.scheduledAt >= :now');
        $qb->setParameter('now', ScheduleCarService::createNewDate());

        if ($hour) {
            $qb->andWhere('ss.hour = :hour')->setParameter('hour', $hour);
        }

        if ($user) {
            $qb->andWhere('ss.telegramUserId = :user')->setParameter('user', $user);
        }

        return $qb->getQuery()->getResult();
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
        $qb->setParameter('now', ScheduleCarService::createNewDate());

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param TelegramUser $user
     * @return ScheduledSet[]
     */
    public function getOwn(TelegramUser $user): array
    {
        $qb = $this->createQueryBuilder('ss');
        $qb
            ->andWhere('ss.telegramUserId = :user')
            ->setParameter('user', $user)
            ->andWhere('ss.scheduledAt >= :now')
            ->setParameter('now', ScheduleCarService::createNewDate())
            ->orderBy('ss.car')
            ->addOrderBy('ss.scheduledAt', 'DESC')
        ;

        return $qb->getQuery()->getResult();
    }

    public function getById(int $id): ?ScheduledSet
    {
        $qb = $this->createQueryBuilder('ss');
        $qb->andWhere('ss.id = :id')->setParameter('id', $id);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @param Car $car
     * @return ScheduledSet[]
     */
    public function getByCar(Car $car): array
    {
        $qb = $this->createQueryBuilder('ss');
        $qb
            ->where('ss.car = :car')
            ->setParameter('car', $car)
            ->andWhere('ss.scheduledAt >= :now')
            ->setParameter('now', ScheduleCarService::createNewDate())
            ->orderBy('ss.scheduledAt', 'DESC')
        ;

        return $qb->getQuery()->getResult();
    }
}
