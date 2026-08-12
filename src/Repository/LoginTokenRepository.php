<?php

namespace App\Repository;

use App\Entity\LoginToken;
use DateTime;
use DateTimeZone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoginToken>
 */
class LoginTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginToken::class);
    }

    public function findByHash(string $hash): ?LoginToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /** Прибирання протермінованих токенів — викликається при видачі нового. */
    public function deleteExpired(): void
    {
        $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt < :now')
            ->setParameter('now', new DateTime('now', new DateTimeZone('Europe/Kyiv')))
            ->getQuery()
            ->execute();
    }
}
