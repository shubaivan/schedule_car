<?php

namespace App\Warehouse\Repository;

use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WhMovement> */
class WhMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhMovement::class);
    }

    /** @return WhMovement[] Рухи, у яких є ця позиція, — новіші зверху. */
    public function historyOf(WhItem $item, int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->innerJoin('m.lines', 'l')
            ->andWhere('l.item = :item')
            ->setParameter('item', $item)
            ->orderBy('m.occurredAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return WhMovement[] Рухи, що приїхали на місце чи поїхали з нього. */
    public function ofSite(WhSite $site, int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.fromSite = :site OR m.toSite = :site')
            ->setParameter('site', $site)
            ->orderBy('m.occurredAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return WhMovement[] Рухи на всіх об'єктах клієнта. */
    public function ofClient(WhClient $client, int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.fromSite', 'f')
            ->leftJoin('m.toSite', 't')
            ->andWhere('f.client = :client OR t.client = :client')
            ->setParameter('client', $client)
            ->orderBy('m.occurredAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return WhMovement[] */
    public function latest(int $limit = 100): array
    {
        return $this->findBy([], ['occurredAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
