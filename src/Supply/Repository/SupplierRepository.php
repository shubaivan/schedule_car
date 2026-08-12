<?php

namespace App\Supply\Repository;

use App\Supply\Entity\Supplier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Supplier>
 */
class SupplierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Supplier::class);
    }

    /** @return Supplier[] */
    public function findActive(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.active = true')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByName(string $name): ?Supplier
    {
        return $this->findOneBy(['nameNormalized' => Supplier::normalize($name)]);
    }

    public function findOneByEdrpou(string $edrpou): ?Supplier
    {
        return $this->findOneBy(['edrpou' => trim($edrpou)]);
    }

    /**
     * Пошук для підказки: за назвою, ЄДРПОУ або контактною особою.
     *
     * @return Supplier[]
     */
    public function search(string $query, int $limit = 20, bool $onlyActive = true): array
    {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.name', 'ASC')
            ->setMaxResults($limit);

        if ($onlyActive) {
            $qb->andWhere('s.active = true');
        }

        $query = trim($query);

        if ($query !== '') {
            $qb
                ->andWhere($qb->expr()->orX(
                    'LOWER(s.name) LIKE :like',
                    's.edrpou LIKE :like',
                    'LOWER(s.contactPerson) LIKE :like',
                ))
                ->setParameter('like', '%' . mb_strtolower($query) . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Останні використані постачальники — щоб у боті менеджер обирав кнопкою,
     * а не шукав руками. Поки закупівель немає, це просто свіжододані.
     *
     * @return Supplier[]
     */
    public function findRecent(int $limit = 8): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.active = true')
            ->orderBy('s.updated_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
