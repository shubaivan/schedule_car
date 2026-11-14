<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Автор виправив свій коментар (стара версія — у task_message_edit). */
final class CommentEdited extends PlanEvent
{
    public const string TYPE = 'comment_edited';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly int $refId,
        public readonly string $excerpt = '',
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
