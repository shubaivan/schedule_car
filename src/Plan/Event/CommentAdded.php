<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Коментар у стрічці (задачі чи заявки). refId — task_message.id / supply_comment.id; files — скільки файлів прийшло разом із ним. */
final class CommentAdded extends PlanEvent
{
    public const string TYPE = 'comment_added';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly int $refId,
        public readonly string $excerpt = '',
        /** @var list<int> */
        public readonly array $mentions = [],
        public readonly int $files = 0,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
