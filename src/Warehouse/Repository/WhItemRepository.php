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
            ->leftJoin('i.supplier', 'sup')->addSelect('sup')
            ->orderBy('i.inventoryNumber', 'ASC');

        if ($query !== '') {
            $qb->andWhere('LOWER(i.name) LIKE :q OR LOWER(i.inventoryNumber) LIKE :q OR LOWER(i.serialNumber) LIKE :q OR LOWER(sup.name) LIKE :q')
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

    /**
     * Одиниці для вибору у формі: звичні плюс усі, що вже є в базі. Вільним
     * текстом тут швидко заводяться «шт», «шт.» і «штук» — три одиниці для
     * однієї, і залишки вже не складеш.
     *
     * @return list<string>
     */
    public function units(): array
    {
        $used = $this->createQueryBuilder('i')
            ->select('DISTINCT i.unit')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_unique(array_merge(['шт', 'компл.', 'м', 'м²', 'м³', 'кг', 'т', 'л', 'пог. м'], array_filter($used))));
    }
}
