<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Змінено поля суб'єкта (назва, опис, мітка заявки…), без зміни статусу й учасників. */
final class SubjectUpdated extends PlanEvent
{
    public const string TYPE = 'subject_updated';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        /** @var list<string> */
        public readonly array $fields = [],
        public readonly ?string $summary = null,
        public readonly ?int $refId = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
