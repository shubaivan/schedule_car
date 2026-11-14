<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Файлом поділились (з людьми в «Плані» або тимчасовим посиланням). Лише аудит. */
final class ShareCreated extends PlanEvent
{
    public const string TYPE = 'share_created';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly int $fileId,
        public readonly string $fileType,
        /** @var list<int> */
        public readonly array $userIds = [],
        public readonly ?string $via = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
