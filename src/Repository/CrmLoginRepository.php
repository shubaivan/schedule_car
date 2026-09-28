<?php

namespace App\Repository;

use App\Entity\CrmLogin;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CrmLogin> */
class CrmLoginRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmLogin::class);
    }

    /** @return CrmLogin[] */
    public function latest(int $limit = 300): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.user', 'u')->addSelect('u')
            ->orderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Останній вдалий вхід кожної людини — «хто давно не заходив» видно одразу.
     *
     * @return array<string, DateTime> ім'я => коли
     */
    public function lastSeen(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('l.name AS name, MAX(l.at) AS at')
            ->andWhere('l.success = true')
            ->andWhere('l.name IS NOT NULL')
            ->groupBy('l.name')
            ->orderBy('at', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $seen = [];

        foreach ($rows as $row) {
            $seen[(string) $row['name']] = new DateTime((string) $row['at']);
        }

        return $seen;
    }

    public function failedSince(DateTime $since): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.success = false')
            ->andWhere('l.at >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
