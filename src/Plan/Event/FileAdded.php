<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Додано файл(и). fileIds — task_attachment / supply_attachment / wh_document; refId — повідомлення задачі, з яким вони прийшли. notify=false — сповіщення вже покриє наступна дія. */
final class FileAdded extends PlanEvent
{
    public const string TYPE = 'file_added';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        /** @var list<int> */
        public readonly array $fileIds = [],
        /** @var list<string> */
        public readonly array $names = [],
        public readonly ?int $refId = null,
        public readonly bool $notify = true,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
