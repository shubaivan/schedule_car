<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Закупівлю скасовано. Постачальник і сума — бо рядка вже немає. */
final class PurchaseRemoved extends PlanEvent
{
    public const string TYPE = 'purchase_removed';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly string $supplier = '',
        public readonly string $total = '',
        public readonly ?int $refId = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
