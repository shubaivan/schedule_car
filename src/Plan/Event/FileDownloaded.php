<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Файл завантажено/відкрито. Лише аудит. fileType: task | supply | wh. */
final class FileDownloaded extends PlanEvent
{
    public const string TYPE = 'file_downloaded';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly int $fileId,
        public readonly string $fileType,
        public readonly ?string $name = null,
        /** share_link.id — файл скачали за тимчасовим посиланням (actorId тоді null). */
        public readonly ?int $shareLinkId = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
