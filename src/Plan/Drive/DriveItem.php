<?php

namespace App\Plan\Drive;

use App\Plan\Share\FileType;
use App\Plan\Subject\SubjectRef;

/**
 * Що й куди класти: ключ (task:12), ім'я файлу на Диску, шлях тек від кореня
 * («План», «2026», «Задачі», «№0012 …»), суб'єкт — для подій і адмінки.
 */
final readonly class DriveItem
{
    /**
     * @param list<string> $folders
     */
    public function __construct(
        public FileType $type,
        public int $id,
        public string $name,
        public string $mime,
        public array $folders,
        public SubjectRef $subject,
    ) {
    }

    public function key(): string
    {
        return $this->type->value . ':' . $this->id;
    }

    public function path(): string
    {
        return implode('/', [...$this->folders, $this->name]);
    }
}
