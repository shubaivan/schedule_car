<?php

namespace App\Plan\Drive;

/** Файл на Диску: id і посилання для перегляду (доступне лише власнику Диска). */
final readonly class DriveUpload
{
    public function __construct(
        public string $id,
        public ?string $url,
    ) {
    }
}
