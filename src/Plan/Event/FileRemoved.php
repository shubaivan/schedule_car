<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Файл(и) прибрано. Назви — бо рядка файлу вже немає. */
final class FileRemoved extends PlanEvent
{
    public const string TYPE = 'file_removed';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        /** @var list<string> */
        public readonly array $names = [],
        /** @var list<int> */
        public readonly array $fileIds = [],
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
