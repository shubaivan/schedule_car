<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** До суб'єкта додали людей (учасників задачі). */
final class MemberAdded extends PlanEvent
{
    public const string TYPE = 'member_added';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        /** @var list<int> */
        public readonly array $userIds = [],
        public readonly ?int $refId = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
