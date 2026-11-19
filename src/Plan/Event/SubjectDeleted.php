<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/**
 * Задачу видалено в кошик (purged = false, actor — хто видалив) або остаточно прибрано
 * з кошика через 30 днів (purged = true, actor = null, tasks:trash:purge). title — назва на той момент.
 */
final class SubjectDeleted extends PlanEvent
{
    public const string TYPE = 'subject_deleted';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly string $title = '',
        public readonly bool $purged = false,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
