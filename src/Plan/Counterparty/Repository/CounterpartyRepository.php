<?php

namespace App\Plan\Counterparty\Repository;

use App\Plan\Counterparty\Entity\Counterparty;
use App\Plan\Counterparty\Enum\CounterpartyKind;
use App\Plan\Counterparty\Service\CounterpartyFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/** @extends ServiceEntityRepository<Counterparty> */
class CounterpartyRepository extends ServiceEntityRepository
{
    /** Скільки рядків віддає список за раз: тисячі контрагентів не тягнемо одним запитом. */
    public const int PAGE = 200;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Counterparty::class);
    }

    public function findOneByCode(string $code): ?Counterparty
    {
        return $this->findOneBy(['code' => $code]);
    }

    public function findOneByExternalCode(string $code): ?Counterparty
    {
        return $this->findOneBy(['externalCode' => $code]);
    }

    /**
     * Контрагент з такою ж нормалізованою назвою і без коду — ймовірний дубль
     * (картку завели вдруге, не знаючи ЄДРПОУ).
     *
     * @return list<Counterparty>
     */
    public function sameName(string $normalized, ?int $exceptId = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.nameNormalized = :n')
            ->setParameter('n', $normalized)
            ->setMaxResults(5);

        if ($exceptId !== null) {
            $qb->andWhere('c.id <> :id')->setParameter('id', $exceptId);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Counterparty> */
    public function search(CounterpartyFilter $filter, int $limit = self::PAGE): array
    {
        // Два кроки: сторінка id за фільтром (LIMIT по картках, не по рядках join-у),
        // потім ці картки разом із менеджером і контактами — без запиту на кожен рядок списку.
        $ids = array_map('intval', array_column($this->filtered($filter, withKind: true)
            ->select('c.id', 'c.nameNormalized')
            ->orderBy('c.nameNormalized', SortDirection::Ascending)
            ->addOrderBy('c.id', SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()->getScalarResult(), 'id'));

        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->leftJoin('c.manager', 'm')->addSelect('m')
            ->leftJoin('c.contacts', 'ct')->addSelect('ct')
            ->andWhere('c.id IN (:ids)')->setParameter('ids', $ids)
            ->orderBy('c.nameNormalized', SortDirection::Ascending)
            ->addOrderBy('c.id', SortDirection::Ascending)
            ->addOrderBy('ct.position', SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /**
     * Лічильники фасетів «Усі / Клієнти / Постачальники / Архів» — при тих самих
     * пошуку й інших фільтрах, але без самого фасета виду.
     *
     * @return array{all: int, client: int, supplier: int, archived: int}
     */
    public function counts(CounterpartyFilter $filter): array
    {
        $active = $filter->with(archived: false);
        $rows = $this->filtered($active, withKind: false)
            ->select('c.kind AS kind, COUNT(c.id) AS n')
            ->groupBy('c.kind')
            ->getQuery()->getArrayResult();

        $by = [];

        foreach ($rows as $row) {
            $kind = $row['kind'] instanceof CounterpartyKind ? $row['kind']->value : (string) $row['kind'];
            $by[$kind] = (int) $row['n'];
        }

        $archived = (int) $this->filtered($filter->with(archived: true), withKind: true)
            ->select('COUNT(c.id)')
            ->getQuery()->getSingleScalarResult();

        return [
            'all' => array_sum($by),
            'client' => ($by['client'] ?? 0) + ($by['both'] ?? 0),
            'supplier' => ($by['supplier'] ?? 0) + ($by['both'] ?? 0),
            'archived' => $archived,
        ];
    }

    private function filtered(CounterpartyFilter $filter, bool $withKind): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c');
        $qb->andWhere($filter->archived ? 'c.archivedAt IS NOT NULL' : 'c.archivedAt IS NULL');

        if ($withKind && $filter->kind !== null) {
            // «Клієнти» — і ті, хто водночас постачальник.
            $qb->andWhere('c.kind IN (:kinds)')->setParameter('kinds', [$filter->kind, CounterpartyKind::Both]);
        }

        if ($filter->legalForm !== null) {
            $qb->andWhere('c.legalForm = :form')->setParameter('form', $filter->legalForm);
        }

        if ($filter->vatStatus !== null) {
            $qb->andWhere('c.vatStatus = :vat')->setParameter('vat', $filter->vatStatus);
        }

        if ($filter->paymentTerms !== null) {
            $qb->andWhere('c.paymentTerms = :terms')->setParameter('terms', $filter->paymentTerms);
        }

        if ($filter->managerId === 0) {
            $qb->andWhere('c.manager IS NULL');
        } elseif ($filter->managerId !== null) {
            $qb->andWhere('IDENTITY(c.manager) = :manager')->setParameter('manager', $filter->managerId);
        }

        $q = trim($filter->q);

        if ($q !== '') {
            $or = $qb->expr()->orX();
            $normalized = Counterparty::normalize($q);

            if ($normalized !== '') {
                $or->add('c.nameNormalized LIKE :name');
                $or->add('LOWER(c.shortName) LIKE :plain');
                $qb->setParameter('name', '%' . addcslashes($normalized, '%_\\') . '%');
                $qb->setParameter('plain', '%' . addcslashes(mb_strtolower($q), '%_\\') . '%');
            }

            $digits = (string) preg_replace('/\D+/', '', $q);

            if (strlen($digits) >= 3) {
                // Код за початком; телефон (свій чи контактної особи) — за будь-якою частиною.
                $or->add('c.code LIKE :code');
                $or->add('c.phone LIKE :phone');
                $or->add('EXISTS (SELECT 1 FROM App\Plan\Counterparty\Entity\CounterpartyContact pc WHERE pc.counterparty = c AND pc.phone LIKE :phone)');
                $qb->setParameter('code', $digits . '%');
                $qb->setParameter('phone', '%' . $digits . '%');
            }

            if ($normalized !== '') {
                $or->add('EXISTS (SELECT 1 FROM App\Plan\Counterparty\Entity\CounterpartyContact nc WHERE nc.counterparty = c AND LOWER(nc.name) LIKE :plain)');
                $or->add('LOWER(c.externalCode) = :ext');
                $qb->setParameter('ext', mb_strtolower($q));
            }

            if ($or->count() === 0) {
                $qb->andWhere('1 = 0');
            } else {
                $qb->andWhere($or);
            }
        }

        return $qb;
    }
}
