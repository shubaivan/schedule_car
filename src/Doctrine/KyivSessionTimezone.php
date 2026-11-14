<?php

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * Одне правило часу: усі дати в БД — київський настінний час (timestamp without
 * time zone), як і в PHP (Kernel::boot → Europe/Kyiv). Щоб LOCALTIMESTAMP / NOW()
 * у SQL (позначки прочитання, доставки, consumed_at…) збігались із часом, який
 * пише PHP, сесія PostgreSQL теж живе в Europe/Kyiv — незалежно від timezone у
 * postgresql.conf (на сервері Плану вона й так Europe/Kyiv; у Docker/CI — UTC,
 * звідки й «прочитано на 2 год раніше, ніж створено»).
 */
#[AsMiddleware]
final class KyivSessionTimezone implements Middleware
{
    public const string ZONE = 'Europe/Kyiv';

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);

                if (in_array($params['driver'] ?? 'pdo_pgsql', ['pdo_pgsql', 'pgsql'], true)) {
                    $connection->exec("SET TIME ZONE '" . KyivSessionTimezone::ZONE . "'");
                }

                return $connection;
            }
        };
    }
}
