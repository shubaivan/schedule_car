<?php

namespace App\Warehouse\Repository;

use App\Warehouse\Entity\WhCategory;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Enum\ItemState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WhItem> */
class WhItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhItem::class);
    }

    /**
     * Список для головної сторінки складу.
     *
     * @param string $place '' — усе; 'none' — ще не надійшло; інакше id місця
     *
     * @return WhItem[]
     */
    public function search(string $query = '', ?WhCategory $category = null, string $place = '', bool $withWrittenOff = false): array
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.currentSite', 's')->addSelect('s')
            ->innerJoin('i.category', 'c')->addSelect('c')
            ->orderBy('i.inventoryNumber', 'ASC');

        if ($query !== '') {
            $qb->andWhere('LOWER(i.name) LIKE :q OR LOWER(i.inventoryNumber) LIKE :q OR LOWER(i.serialNumber) LIKE :q OR LOWER(i.supplier) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($query) . '%');
        }

        if ($category !== null) {
            $qb->andWhere('i.category = :category')->setParameter('category', $category);
        }

        if ($place === 'none') {
            $qb->andWhere('i.currentSite IS NULL');
        } elseif (ctype_digit($place)) {
            $qb->andWhere('i.currentSite = :site')->setParameter('site', (int) $place);
        }

        if (! $withWrittenOff) {
            $qb->andWhere('i.state <> :off')->setParameter('off', ItemState::WrittenOff);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return WhItem[] Що можна додати в рух — усе, крім списаного. */
    public function movable(): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.state <> :off')->setParameter('off', ItemState::WrittenOff)
            ->orderBy('i.inventoryNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
