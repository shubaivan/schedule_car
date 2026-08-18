<?php

namespace App\Service;

use DateTime;
use DateTimeZone;

/**
 * Час заводу. Уся система рахує дні й години в київському поясі — сервер може
 * стояти в UTC, але «завтра о 8:00» для людини одне.
 */
final class KyivTime
{
    public const ZONE = 'Europe/Kyiv';

    public static function now(): DateTime
    {
        return new DateTime('now', new DateTimeZone(self::ZONE));
    }

    public static function today(): DateTime
    {
        return new DateTime('today', new DateTimeZone(self::ZONE));
    }

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::ZONE);
    }
}
