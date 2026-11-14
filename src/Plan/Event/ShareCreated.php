<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Файлом поділились (з людьми в «Плані» — ще й сповіщення адресатам; тимчасовим посиланням — лише аудит). */
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
        /** Записка до «поділитися» з людьми (до 500 символів). */
        public readonly ?string $note = null,
        /** share_link.id — для посилання /s/{token}. */
        public readonly ?int $linkId = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
