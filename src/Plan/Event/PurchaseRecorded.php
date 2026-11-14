<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Записано (або виправлено — updated) закупівлю за заявкою. notify=false — закупівля йде впритул зі зміною статусу, повідомлення одне. */
final class PurchaseRecorded extends PlanEvent
{
    public const string TYPE = 'purchase_recorded';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly int $refId,
        public readonly string $supplier = '',
        public readonly string $total = '',
        public readonly bool $updated = false,
        public readonly bool $notify = true,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
