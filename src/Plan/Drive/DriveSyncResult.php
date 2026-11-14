<?php

namespace App\Plan\Drive;

/** Підсумок одного прогону DriveSync — для команди й тестів. */
final class DriveSyncResult
{
    public const string STOP_AUTH = 'auth';
    public const string STOP_QUOTA = 'quota';
    public const string STOP_LOCKED = 'locked';

    public int $sent = 0;
    public int $failed = 0;
    public int $skipped = 0;
    public int $alreadyThere = 0;
    public ?string $stopped = null;

    /** @var list<string> */
    public array $lines = [];

    public function line(string $line): void
    {
        $this->lines[] = $line;
    }
}
