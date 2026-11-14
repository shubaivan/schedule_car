<?php

namespace App\Tests\Plan;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Одне правило часу: сесія PostgreSQL — Europe/Kyiv, як PHP. LOCALTIMESTAMP у SQL
 * (read_at, delivered_*_at, consumed_at) і час, який пише PHP, — той самий годинник,
 * навіть коли сервер БД (Docker, CI) у UTC.
 */
final class DbTimezoneTest extends KernelTestCase
{
    public function testDbSessionClockMatchesPhpKyivClock(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);

        self::assertSame('Europe/Kyiv', $db->fetchOne('SHOW timezone'));

        $php = new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'));
        $sql = new DateTimeImmutable((string) $db->fetchOne('SELECT LOCALTIMESTAMP(0)'), new DateTimeZone('Europe/Kyiv'));

        self::assertLessThan(5, abs($sql->getTimestamp() - $php->getTimestamp()), 'LOCALTIMESTAMP ≠ київський час PHP');
    }
}
