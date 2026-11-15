<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/**
 * Суперадмін вирішив заявку на приєднання. decision: approved | rejected.
 * Затверджено — суб'єкт новий співробітник (user-N), роль у role; відхилено — user-0.
 */
final class JoinDecided extends PlanEvent
{
    public const string TYPE = 'join_decided';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly int $requestId = 0,
        public readonly string $decision = 'approved',
        public readonly ?string $name = null,
        public readonly ?string $role = null,
        public readonly ?string $reason = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
