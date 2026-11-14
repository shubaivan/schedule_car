<?php

namespace App\Plan\Telegram\Entity;

use App\Entity\TelegramUser;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Одноразовий токен «прив'язати Telegram»: профіль Плану → deep link у бота
 * (`t.me/<бот>?start=plink_<токен>`). У базі — лише sha256 токена.
 */
#[ORM\Entity]
#[ORM\Table(name: 'telegram_link_token')]
#[ORM\Index(name: 'idx_telegram_link_token_user', columns: ['user_id'])]
class TelegramLinkToken
{
    #[ORM\Id]
    #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64)]
    private string $tokenHash;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private TelegramUser $user;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $usedAt = null;

    public function __construct(string $tokenHash, TelegramUser $user, DateTimeImmutable $expiresAt)
    {
        $this->tokenHash = $tokenHash;
        $this->user = $user;
        $this->createdAt = new DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public function getUser(): TelegramUser
    {
        return $this->user;
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        return $this->usedAt === null && $now < $this->expiresAt;
    }

    public function use(DateTimeImmutable $now): void
    {
        $this->usedAt = $now;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
