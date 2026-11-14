<?php

namespace App\Plan\Drive\Source;

use App\Plan\Drive\DriveItem;
use App\Plan\Share\FileType;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Таблиця файлів, які «План» копіює на Диск. Колонки стану однакові
 * (drive_file_id, drive_url, drive_state, drive_attempts, drive_error, drive_next_at —
 * DriveSyncState), тож чергою керує DriveSync, а джерело лише каже, що це за файл
 * і де він лежить у нашому сховищі.
 */
#[AutoconfigureTag('plan.drive_source')]
interface DriveSource
{
    public function type(): FileType;

    /** null — файла вже немає або його видалили до копіювання (стан skipped). */
    public function item(int $id): ?DriveItem;

    /** Вміст із нашого сховища (джерело правди — воно, не Диск). */
    public function read(int $id): string;
}
