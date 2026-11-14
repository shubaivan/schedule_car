<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Людей згадали (@) у вже наявному повідомленні. Згадка в новому коментарі — частина CommentAdded::\$mentions, окремого рядка не дає. */
final class MentionAdded extends PlanEvent
{
    public const string TYPE = 'mention_added';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly int $refId,
        /** @var list<int> */
        public readonly array $userIds = [],
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
