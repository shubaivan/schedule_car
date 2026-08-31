<?php

namespace App\Supply\Repository;

use App\Entity\TelegramUser;
use App\Supply\Entity\Department;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyStatus;
use DateTime;
use DateTimeZone;
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

        return sprintf('%03d%s', ((int) $max) + 1, $suffix);
    }

    /**
     * Список для CRM менеджера.
     *
     * @param array{
     *     status?: SupplyStatus[],
     *     department?: ?int,
     *     author?: ?int,
     *     urgent?: ?bool,
     *     accent?: ?string,
     *     overdue?: ?bool,
     *     query?: ?string,
     *     open?: ?bool
     * } $filters
     *
     * @return array{items: SupplyRequest[], total: int}
     */
    public function search(array $filters, int $page = 1, int $limit = 25): array
    {
        $qb = $this->createQueryBuilder('r')
            ->addSelect('a', 'd')
            ->leftJoin('r.author', 'a')
            ->leftJoin('r.department', 'd');

        $this->applyFilters($qb, $filters);

        // Спершу помічені менеджером — червоні, жовті, зелені, — далі найновіші.
        // Порядок задаємо явно: значення enum рядкові, і алфавіт дав би нісенітницю.
        $qb->addSelect(
            "CASE r.accent WHEN 'red' THEN 0 WHEN 'yellow' THEN 1 WHEN 'green' THEN 2 ELSE 3 END AS HIDDEN accent_rank",
        );

        $qb->orderBy('accent_rank', 'ASC')
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
            $counts[(string) $row['status']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function countByDepartment(Department $department): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.department = :department')
            ->setParameter('department', $department)
            ->getQuery()
            ->getSingleScalarResult();
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
     * За замовчуванням віддає тільки ті, по яких ще не нагадували на цей строк:
     * крон ходить щодня, і без цього фільтра менеджер щоранку отримував той
     * самий список. Перенесли строк (needBy пізніше мітки) — заявка знову тут.
     *
     * @param bool $includeReminded віддати й ті, по яких уже нагадували (--force)
     *
     * @return SupplyRequest[]
     */
    public function findOverdue(bool $includeReminded = false): array
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.needBy IS NOT NULL')
            ->andWhere('r.needBy < :today')
            ->andWhere('r.status IN (:open)')
            ->setParameter('today', new DateTime('today', new DateTimeZone('Europe/Kyiv')))
            ->setParameter('open', SupplyStatus::openCases())
            ->orderBy('r.needBy', 'ASC');

        if (! $includeReminded) {
            $qb->andWhere('r.overdueNotifiedAt IS NULL OR r.overdueNotifiedAt < r.needBy');
        }

        return $qb->getQuery()->getResult();
    }

    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (! empty($filters['status'])) {
            $qb->andWhere('r.status IN (:statuses)')
                ->setParameter('statuses', $filters['status']);
        }

        if (! empty($filters['open'])) {
            $qb->andWhere('r.status IN (:openStatuses)')
                ->setParameter('openStatuses', SupplyStatus::openCases());
        }

        if (! empty($filters['department'])) {
            $qb->andWhere('IDENTITY(r.department) = :department')
                ->setParameter('department', $filters['department']);
        }

        if (! empty($filters['author'])) {
            $qb->andWhere('IDENTITY(r.author) = :author')
                ->setParameter('author', $filters['author']);
        }

        if (! empty($filters['accent'])) {
            $qb->andWhere('r.accent = :accent')->setParameter('accent', $filters['accent']);
        }

        if (! empty($filters['urgent'])) {
            $qb->andWhere('r.urgent = true');
        }

        if (! empty($filters['overdue'])) {
            $qb->andWhere('r.needBy IS NOT NULL')
                ->andWhere('r.needBy < :today')
                ->andWhere('r.status IN (:openForOverdue)')
                ->setParameter('today', new DateTime('today', new DateTimeZone('Europe/Kyiv')))
                ->setParameter('openForOverdue', SupplyStatus::openCases());
        }

        if (! empty($filters['query'])) {
            $qb->andWhere('LOWER(r.item) LIKE :q OR LOWER(r.number) LIKE :q OR LOWER(r.site) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower(trim($filters['query'])) . '%');
        }
    }
}
