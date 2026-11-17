<?php

namespace App\Plan\Login\Entity;

use App\Entity\TelegramUser;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Одноразове посилання «встановити пароль» — приходить ЛИШЕ в Telegram людини
 * (бот Плану). Через нього ставлять перший пароль і відновлюють забутий; іншого
 * шляху задати пароль у «Плані» немає (ні email, ні адмін у картці).
 *
 * У базі — лише sha256 токена; живе 30 хв, гасне після першого збереження пароля,
 * а нове посилання гасить попередні. GET лише показує форму (прев'ю посилання в
 * Telegram не витрачає його), пароль приймає лише POST із CSRF.
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan_password_token')]
#[ORM\Index(name: 'idx_plan_password_token_user', columns: ['user_id'])]
class PasswordToken
{
    public const int TTL_MINUTES = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64, unique: true)]
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

    public function __construct(TelegramUser $user, string $tokenHash, DateTimeImmutable $now)
    {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->createdAt = $now;
        $this->expiresAt = $now->modify('+' . self::TTL_MINUTES . ' minutes');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): TelegramUser
    {
        return $this->user;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        return $this->usedAt === null && $now < $this->expiresAt;
    }

    public function markUsed(DateTimeImmutable $now): void
    {
        $this->usedAt = $now;
    }
}
