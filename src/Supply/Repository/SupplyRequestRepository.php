<?php

namespace App\Supply\Repository;

use App\Entity\TelegramUser;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupplyRequest>
 */
class SupplyRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplyRequest::class);
    }

    /**
     * Наступний номер у форматі 042/2026. Гонку двох одночасних заявок ловить
     * унікальний індекс на number — виклик повторюється (див. CreateRequest).
     */
    public function nextNumber(int $year): string
    {
        $suffix = '/' . $year;

        $max = $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT MAX(CAST(SPLIT_PART(number, '/', 1) AS INTEGER)) FROM supply_request WHERE number LIKE :suffix",
            ['suffix' => '%' . $suffix],
        );

        return sprintf('%03d%s', ((int)$max) + 1, $suffix);
    }

    /**
     * Список для CRM менеджера.
     *
     * @param array{
     *     status?: SupplyStatus[],
     *     department?: ?int,
     *     author?: ?int,
     *     urgent?: ?bool,
     *     overdue?: ?bool,
     *     query?: ?string,
     *     open?: ?bool
     * } $filters
     * @return array{items: SupplyRequest[], total: int}
     */
    public function search(array $filters, int $page = 1, int $limit = 25): array
    {
        $qb = $this->createQueryBuilder('r')
            ->addSelect('a', 'd')
            ->leftJoin('r.author', 'a')
            ->leftJoin('r.department', 'd');

        $this->applyFilters($qb, $filters);

        // Термінові — вгору, далі найновіші.
        $qb->orderBy('r.urgent', 'DESC')
            ->addOrderBy('r.created_at', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $paginator = new Paginator($qb->getQuery(), false);

        return [
            'items' => iterator_to_array($paginator),
            'total' => count($paginator),
        ];
    }

    /** @return array<string, int> статус => кількість, для лічильників у CRM */
    public function countByStatus(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.status AS status, COUNT(r.id) AS cnt')
            ->groupBy('r.status');

        $this->applyFilters($qb, $filters);

        $counts = [];
        foreach ($qb->getQuery()->getScalarResult() as $row) {
            $counts[(string)$row['status']] = (int)$row['cnt'];
        }

        return $counts;
    }

    /** @return SupplyRequest[] */
    public function findByAuthor(TelegramUser $author, int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.author = :author')
            ->setParameter('author', $author)
            ->orderBy('r.created_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Заявки, у яких вийшов термін, а вони ще в роботі — для нагадувань по крону.
     *
     * @return SupplyRequest[]
     */
    public function findOverdue(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.needBy IS NOT NULL')
            ->andWhere('r.needBy < :today')
            ->andWhere('r.status IN (:open)')
            ->setParameter('today', new \DateTime('today', new \DateTimeZone('Europe/Kyiv')))
            ->setParameter('open', SupplyStatus::openCases())
            ->orderBy('r.needBy', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['status'])) {
            $qb->andWhere('r.status IN (:statuses)')
                ->setParameter('statuses', $filters['status']);
        }

        if (!empty($filters['open'])) {
            $qb->andWhere('r.status IN (:openStatuses)')
                ->setParameter('openStatuses', SupplyStatus::openCases());
        }

        if (!empty($filters['department'])) {
            $qb->andWhere('IDENTITY(r.department) = :department')
                ->setParameter('department', $filters['department']);
        }

        if (!empty($filters['author'])) {
            $qb->andWhere('IDENTITY(r.author) = :author')
                ->setParameter('author', $filters['author']);
        }

        if (!empty($filters['urgent'])) {
            $qb->andWhere('r.urgent = true');
        }

        if (!empty($filters['overdue'])) {
            $qb->andWhere('r.needBy IS NOT NULL')
                ->andWhere('r.needBy < :today')
                ->andWhere('r.status IN (:openForOverdue)')
                ->setParameter('today', new \DateTime('today', new \DateTimeZone('Europe/Kyiv')))
                ->setParameter('openForOverdue', SupplyStatus::openCases());
        }

        if (!empty($filters['query'])) {
            $qb->andWhere('LOWER(r.item) LIKE :q OR LOWER(r.number) LIKE :q OR LOWER(r.site) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower(trim($filters['query'])) . '%');
        }
    }
}
