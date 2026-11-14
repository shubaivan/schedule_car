<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Нова позиція складу (суб'єкт — wh_item). */
final class ItemCreated extends PlanEvent
{
    public const string TYPE = 'item_created';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly string $title = '',
        public readonly ?int $refId = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
