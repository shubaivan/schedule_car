<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Людей прибрали з суб'єкта. */
final class MemberRemoved extends PlanEvent
{
    public const string TYPE = 'member_removed';

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
