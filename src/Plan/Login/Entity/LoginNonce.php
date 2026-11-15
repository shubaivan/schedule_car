<?php

namespace App\Plan\Login\Entity;

use App\Entity\TelegramUser;
use App\Plan\Login\LoginNonceStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Одна спроба «Увійти через Telegram» з одного браузера.
 *
 * Сайт видає випадковий nonce (у базі — лише sha256), сесія браузера пам'ятає id
 * рядка й той самий хеш. Бот отримує `/start login_<nonce>`, знаходить рядок за хешем,
 * «забирає» його на свій Telegram (telegram_id) і підтверджує (status = confirmed,
 * user_id). Браузер, побачивши confirmed, один раз міняє рядок на used — і лише тоді
 * отримує сесію. Код (4 цифри) показують і сайт, і бот: людина бачить, що підтверджує
 * саме свій вхід.
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan_login_nonce')]
#[ORM\Index(name: 'idx_plan_login_nonce_tg', columns: ['telegram_id', 'status'])]
#[ORM\Index(name: 'idx_plan_login_nonce_expires', columns: ['expires_at'])]
class LoginNonce
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'nonce_hash', type: Types::STRING, length: 64, unique: true)]
    private string $nonceHash;

    #[ORM\Column(name: 'code', type: Types::STRING, length: 8)]
    private string $code;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 16, enumType: LoginNonceStatus::class, options: ['default' => 'pending'])]
    private LoginNonceStatus $status = LoginNonceStatus::Pending;

    /** Хто відкрив посилання в боті; другий Telegram з тим самим nonce — відмова. */
    #[ORM\Column(name: 'telegram_id', type: Types::STRING, length: 32, nullable: true)]
    private ?string $telegramId = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?TelegramUser $user = null;

    #[ORM\Column(name: 'ip', type: Types::STRING, length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(name: 'user_agent', type: Types::STRING, length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $usedAt = null;

    public function __construct(string $nonceHash, string $code, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt, ?string $ip, ?string $userAgent)
    {
        $this->nonceHash = $nonceHash;
        $this->code = $code;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
        $this->ip = $ip;
        $this->userAgent = $userAgent !== null ? mb_substr($userAgent, 0, 255) : null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNonceHash(): string
    {
        return $this->nonceHash;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getStatus(): LoginNonceStatus
    {
        return $this->status;
    }

    public function getTelegramId(): ?string
    {
        return $this->telegramId;
    }

    public function getUser(): ?TelegramUser
    {
        return $this->user;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getConfirmedAt(): ?DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
