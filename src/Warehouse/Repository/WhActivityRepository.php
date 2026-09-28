<?php

namespace App\Warehouse\Repository;

use App\Entity\TelegramUser;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Enum\ActivityAction;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WhActivity> */
class WhActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhActivity::class);
    }

    /** @return WhActivity[] Журнал однієї картки, новіші зверху. */
    public function of(string $subjectType, int $subjectId, int $limit = 30): array
    {
        return $this->findBy(['subjectType' => $subjectType, 'subjectId' => $subjectId], ['id' => 'DESC'], $limit);
    }

    /** @return WhActivity[] */
    public function search(
        ?TelegramUser $user = null,
        ?ActivityAction $action = null,
        ?string $subjectType = null,
        ?DateTime $from = null,
        ?DateTime $to = null,
        int $limit = 300,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')->addSelect('u')
            ->orderBy('a.id', 'DESC')
            ->setMaxResults($limit);

        if ($user !== null) {
            $qb->andWhere('a.user = :user')->setParameter('user', $user);
        }

        if ($action !== null) {
            $qb->andWhere('a.action = :action')->setParameter('action', $action);
        }

        if ($subjectType !== null) {
            $qb->andWhere('a.subjectType = :type')->setParameter('type', $subjectType);
        }

        if ($from !== null) {
            $qb->andWhere('a.at >= :from')->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('a.at < :to')->setParameter('to', (clone $to)->modify('+1 day'));
        }

        return $qb->getQuery()->getResult();
    }

    /** @return TelegramUser[] Хто взагалі є в журналі — для фільтра. */
    public function people(): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(TelegramUser::class, 'u')
            ->where('u.id IN (SELECT IDENTITY(a.user) FROM ' . WhActivity::class . ' a)')
            ->orderBy('u.first_name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
