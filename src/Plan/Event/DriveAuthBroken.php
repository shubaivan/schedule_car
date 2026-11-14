<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/**
 * Google відкликав доступ до Диска (invalid_grant): копіювання «Плану» стоїть,
 * доки суперадмін не видасть новий токен і не натисне «Відновити». Пишеться ОДИН
 * раз на поломку (DriveSync), суб'єкт — файл, на якому зламалось. Лише аудит +
 * сповіщення суперадмінам (NotificationFanout, група «system»).
 */
final class DriveAuthBroken extends PlanEvent
{
    public const string TYPE = 'drive_auth_broken';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly string $fileType = '',
        public readonly int $fileId = 0,
        public readonly string $error = '',
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
