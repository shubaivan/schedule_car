<?php

namespace App\Plan\Share\Entity;

use App\Entity\TelegramUser;
use App\Plan\Share\FileType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Тимчасове посилання на файл для людей поза «Планом»: /s/{token}.
 *
 * Сам токен ніде не зберігається — лише його SHA-256 (витік бази не дає робочих
 * посилань). Живе TTL днів, відкликається кнопкою; файл віддається, лише поки
 * автор посилання сам має доступ до файлу. Drive-«anyone with link» навмисно
 * не використовуємо: те публікує файл назавжди й поза журналом.
 */
#[ORM\Entity]
#[ORM\Table(name: 'share_link')]
#[ORM\UniqueConstraint(name: 'uniq_share_link_token', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_share_link_file', columns: ['file_type', 'file_id'])]
class ShareLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64)]
    private string $tokenHash;

    #[ORM\Column(name: 'file_type', type: Types::STRING, length: 8, enumType: FileType::class)]
    private FileType $fileType;

    #[ORM\Column(name: 'file_id', type: Types::INTEGER)]
    private int $fileId;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false, onDelete: 'CASCADE')]
    private TelegramUser $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'revoked_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $revokedBy = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $downloads = 0;

    #[ORM\Column(name: 'last_download_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastDownloadAt = null;

    public function __construct(string $tokenHash, FileType $fileType, int $fileId, TelegramUser $createdBy, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt)
    {
        $this->tokenHash = $tokenHash;
        $this->fileType = $fileType;
        $this->fileId = $fileId;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    public static function now(): DateTimeImmutable
    {
        // Як усі дати в БД — київський настінний час.
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getFileType(): FileType
    {
        return $this->fileType;
    }

    public function getFileId(): int
    {
        return $this->fileId;
    }

    public function getCreatedBy(): TelegramUser
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getRevokedBy(): ?TelegramUser
    {
        return $this->revokedBy;
    }

    public function getDownloads(): int
    {
        return $this->downloads;
    }

    public function getLastDownloadAt(): ?DateTimeImmutable
    {
        return $this->lastDownloadAt;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? self::now());
    }

    public function isActive(?DateTimeImmutable $now = null): bool
    {
        return $this->revokedAt === null && ! $this->isExpired($now);
    }

    public function revoke(TelegramUser $by, ?DateTimeImmutable $at = null): self
    {
        if ($this->revokedAt === null) {
            $this->revokedAt = $at ?? self::now();
            $this->revokedBy = $by;
        }

        return $this;
    }
}
