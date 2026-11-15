<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/**
 * Заявка на приєднання з бота (суб'єкт — «невідома людина», user-0: картки ще немає).
 * Лише аудит. phone — замаскований (+380 •• ••• •6 66), повного номера в журналі немає.
 */
final class JoinRequested extends PlanEvent
{
    public const string TYPE = 'join_requested';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly int $requestId = 0,
        public readonly ?string $name = null,
        public readonly ?string $phone = null,
        public readonly ?string $username = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
