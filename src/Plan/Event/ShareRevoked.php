<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Тимчасове посилання /s/{token} на файл відкликано раніше строку. Лише аудит. */
final class ShareRevoked extends PlanEvent
{
    public const string TYPE = 'share_revoked';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly int $fileId,
        public readonly string $fileType,
        public readonly int $linkId,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
