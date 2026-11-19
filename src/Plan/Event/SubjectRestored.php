<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Задачу відновлено з кошика (actor — хто відновив). title — назва на той момент. */
final class SubjectRestored extends PlanEvent
{
    public const string TYPE = 'subject_restored';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly string $title = '',
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
