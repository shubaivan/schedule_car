<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Перегляд/скан/перехід за посиланням/друк наклейок (журнал складу). Лише аудит, не Хроніка. */
final class SubjectViewed extends PlanEvent
{
    public const string TYPE = 'subject_viewed';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly string $action,
        public readonly ?string $details = null,
        public readonly ?int $refId = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
