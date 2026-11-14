<?php

namespace App\Plan\Drive;

/**
 * Те, що «Плану» потрібно від Google Диска, — і нічого більше: створити теку,
 * покласти файл, знайти свій файл за ключем. Читати чи публікувати чужі файли
 * не вміємо навмисно (scope drive.file, жодних «anyone with link»).
 *
 * Реалізації: GoogleDriveClient (бойова), FakeDriveClient (тести й локальний стенд).
 * Усі збої — DriveException з видом (auth / quota / transient).
 */
interface DriveClient
{
    /** id кореня, під яким росте дерево «План/…» (GOOGLE_DRIVE_ROOT_FOLDER_ID або «Мій диск»). */
    public function rootId(): string;

    /** Знайти або створити теку $name у $parentId; повертає її id. */
    public function folder(string $name, string $parentId): string;

    /** Файл, раніше покладений із цим ключем (appProperties.planKey), — для ідемпотентності. */
    public function findByKey(string $planKey): ?DriveUpload;

    /** Чи є в теці (не в кошику) файл із таким ім'ям. */
    public function nameTaken(string $name, string $parentId): bool;

    public function upload(string $name, string $mime, string $contents, string $parentId, string $planKey): DriveUpload;
}
