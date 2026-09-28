<?php

namespace App\Warehouse\Repository;

use App\Warehouse\Entity\WhClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WhClient> */
class WhClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhClient::class);
    }

    /** @return WhClient[] */
    public function search(string $query = ''): array
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.active', 'DESC')->addOrderBy('c.name', 'ASC');

        if ($query !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :q OR c.edrpou LIKE :q OR LOWER(c.contactPerson) LIKE :q OR c.phone LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($query) . '%');
        }

        return $qb->getQuery()->getResult();
    }
}
