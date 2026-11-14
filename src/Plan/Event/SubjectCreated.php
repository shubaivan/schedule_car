<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Створено суб'єкт (задачу, заявку, клієнта/об'єкт/категорію складу). refId — рядок журналу модуля (task_message, supply_status_log, wh_activity). */
final class SubjectCreated extends PlanEvent
{
    public const string TYPE = 'subject_created';

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
