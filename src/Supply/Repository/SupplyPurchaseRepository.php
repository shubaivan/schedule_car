<?php

namespace App\Supply\Repository;

use App\Supply\Entity\Supplier;
use App\Supply\Entity\SupplyPurchase;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupplyPurchase>
 */
class SupplyPurchaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplyPurchase::class);
    }

    /** Скільки всього закупили в постачальника — основа звіту по обороту. */
    public function totalBySupplier(Supplier $supplier, ?DateTimeInterface $from = null): string
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.totalAmount), 0)')
            ->andWhere('p.supplier = :supplier')
            ->setParameter('supplier', $supplier);

        if ($from !== null) {
            $qb->andWhere('p.created_at >= :from')->setParameter('from', $from);
        }

        return (string) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Постачальники, у яких купували востаннє — для кнопок у боті:
     * менеджер обирає одним дотиком, а не шукає в довіднику.
     *
     * @return Supplier[]
     */
    public function recentSuppliers(int $limit = 6): array
    {
        // Сортуємо за MAX(id), а не за датою: created_at має точність до
        // секунди, і дві покупки в одну секунду дають довільний порядок.
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.supplier) AS supplier_id', 'MAX(p.id) AS last_used')
            ->innerJoin('p.supplier', 's')
            ->andWhere('s.active = true')
            ->groupBy('p.supplier')
            ->orderBy('last_used', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if (! $rows) {
            return [];
        }

        $suppliers = $this->getEntityManager()->getRepository(Supplier::class)
            ->findBy(['id' => array_column($rows, 'supplier_id')]);

        // findBy не тримає порядок — відновлюємо його за списком «востаннє».
        $byId = [];
        foreach ($suppliers as $supplier) {
            $byId[$supplier->getId()] = $supplier;
        }

        return array_values(array_filter(array_map(
            static fn (array $row) => $byId[(int) $row['supplier_id']] ?? null,
            $rows,
        )));
    }
}
