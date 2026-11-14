<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Рух на складі (надходження, відвантаження, повернення, переміщення, списання). Суб'єкт — wh_movement. */
final class StockMoved extends PlanEvent
{
    public const string TYPE = 'stock_moved';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly string $movementType,
        public readonly string $title = '',
        public readonly ?int $fromSiteId = null,
        public readonly ?int $toSiteId = null,
        /** @var list<int> */
        public readonly array $itemIds = [],
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
