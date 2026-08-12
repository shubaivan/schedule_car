<?php

namespace App\Entity;

use App\Repository\LoginTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Одноразовий вхід у CRM за посиланням із бота.
 *
 * Зберігаємо лише hash токена: якщо база витече, з неї не дістати робочих посилань.
 * Токен живе 5 хвилин і згорає при першому використанні.
 */
#[ORM\Entity(repositoryClass: LoginTokenRepository::class)]
#[ORM\Table(name: 'login_token')]
class LoginToken
{
    /** 5 хвилин виявилось замало: посилання встигає протермінуватись, поки його відкриють. */
    public const TTL_MINUTES = 15;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'token_hash', type: 'string', length: 64, unique: true, nullable: false)]
    private string $tokenHash;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private TelegramUser $user;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $expiresAt;

    #[ORM\Column(name: 'used_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $usedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $created_at;

    public function __construct()
    {
        $this->created_at = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $this->expiresAt = (clone $this->created_at)->modify('+' . self::TTL_MINUTES . ' minutes');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;

        return $this;
    }

    public function getUser(): TelegramUser
    {
        return $this->user;
    }

    public function setUser(TelegramUser $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getExpiresAt(): \DateTime
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTime
    {
        return $this->usedAt;
    }

    public function markUsed(): self
    {
        $this->usedAt = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));

        return $this;
    }

    public function isUsable(): bool
    {
        return $this->usedAt === null
            && $this->expiresAt > new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }
}
