<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Лід з AI-бота (суб'єкт — lead). */
final class LeadReceived extends PlanEvent
{
    public const string TYPE = 'lead_received';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly string $source,
        public readonly string $externalId,
        public readonly ?string $name = null,
        public readonly ?string $interest = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
