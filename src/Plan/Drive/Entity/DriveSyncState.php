<?php

namespace App\Plan\Drive\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Стан копії файлу на Google Диску «Плану» — однакові 4 колонки в task_attachment,
 * supply_attachment і wh_document (міграція Version20261115140000).
 *
 * Пише їх лише App\Plan\Drive\DriveSync (DBAL-ом, однаково для всіх таблиць); тут —
 * мапінг, щоб схема збігалась із сутностями, і геттери для показу.
 *
 * pending — чекає (drive_next_at — не раніше ніж); done — на Диску (drive_file_id);
 * failed — 6 невдалих спроб, чекає кнопки «Повторити»; skipped — файл зник/видалений.
 * Файл, який стенд заявок скопіював старим дзеркалом, має drive_file_id при
 * drive_state = pending — це теж «на Диску» (див. driveSynced()).
 */
trait DriveSyncState
{
    #[ORM\Column(name: 'drive_state', type: Types::STRING, length: 8, options: ['default' => 'pending'])]
    private string $driveState = 'pending';

    #[ORM\Column(name: 'drive_attempts', type: Types::INTEGER, options: ['default' => 0])]
    private int $driveAttempts = 0;

    #[ORM\Column(name: 'drive_error', type: Types::TEXT, nullable: true)]
    private ?string $driveError = null;

    #[ORM\Column(name: 'drive_next_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $driveNextAt = null;

    public function getDriveState(): string
    {
        return $this->getDriveFileId() !== null ? 'done' : $this->driveState;
    }

    public function getDriveAttempts(): int
    {
        return $this->driveAttempts;
    }

    public function getDriveError(): ?string
    {
        return $this->driveError;
    }

    public function getDriveNextAt(): ?DateTimeImmutable
    {
        return $this->driveNextAt;
    }

    public function driveSynced(): bool
    {
        return $this->getDriveFileId() !== null;
    }
}
