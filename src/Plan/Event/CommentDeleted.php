<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Коментар прибрано зі стрічки (м'яке видалення). */
final class CommentDeleted extends PlanEvent
{
    public const string TYPE = 'comment_deleted';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly int $refId,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
