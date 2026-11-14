<?php

namespace App\Plan\Drive\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Один рядок (id = 1): стан синхронізації з Диском загалом — для сторінки
 * «Диск» в адмінці й для самого DriveSync (зупинка на invalid_grant, пауза квоти).
 * Пише DriveSync DBAL-ом.
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan_drive_status')]
class DriveStatus
{
    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    private int $id = 1;

    /** Токен відкликано/протух — синхронізація стоїть, доки адмін не натисне «Відновити». */
    #[ORM\Column(name: 'auth_broken_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $authBrokenAt = null;

    /** Квота Google (403/429) — не смикаємо Диск до цього часу. */
    #[ORM\Column(name: 'paused_until', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $pausedUntil = null;

    #[ORM\Column(name: 'last_run_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(name: 'last_ok_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastOkAt = null;

    #[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getAuthBrokenAt(): ?DateTimeImmutable
    {
        return $this->authBrokenAt;
    }

    public function getPausedUntil(): ?DateTimeImmutable
    {
        return $this->pausedUntil;
    }

    public function getLastRunAt(): ?DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function getLastOkAt(): ?DateTimeImmutable
    {
        return $this->lastOkAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}
