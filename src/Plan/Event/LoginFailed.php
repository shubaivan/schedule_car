<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Невдалий вхід. Суб'єкт — user; людину не впізнано → SubjectRef::unknownUser() (id 0). */
final class LoginFailed extends PlanEvent
{
    public const string TYPE = 'login_failed';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly ?string $name = null,
        public readonly ?string $reason = null,
        public readonly ?string $userAgent = null,
        public readonly ?int $refId = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
