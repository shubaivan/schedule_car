<?php

namespace App\Plan\Drive;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * «Скопіюй ці файли на Диск зараз» — окремим повідомленням у транспорт async,
 * щоб 20-мегабайтне завантаження в Google не тримало розсилку подій «Плану»
 * (сповіщення, Хроніку) у воркері plan. Пише DriveEnqueue після FileAdded.
 */
#[AsMessage('async')]
final readonly class SyncDriveFiles
{
    /** @param list<int> $ids */
    public function __construct(
        public string $type,
        public array $ids,
    ) {
    }
}
