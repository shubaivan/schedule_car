<?php

namespace App\Plan\Share;

use App\Plan\Subject\SubjectRef;
use App\Supply\Entity\SupplyAttachment;
use App\Tasks\Entity\TaskAttachment;
use App\Warehouse\Entity\WhDocument;

/**
 * Будь-який файл «Плану» в одному вигляді — для читалки, скачування й «поділитися».
 * subjectLabel — «Задача №0012 Плити…» / «Заявка №042-2026 Цемент» / «Склад: …».
 */
final readonly class PlanFile
{
    public function __construct(
        public FileType $type,
        public int $id,
        public string $name,
        public string $mime,
        public int $size,
        public SubjectRef $subject,
        public string $subjectLabel,
        public TaskAttachment|SupplyAttachment|WhDocument $entity,
    ) {
    }

    public function key(): string
    {
        return $this->type->value . '-' . $this->id;
    }

    public function driveUrl(): ?string
    {
        return $this->entity->getDriveUrl();
    }

    public function driveState(): string
    {
        return $this->entity->getDriveState();
    }
}
