<?php

namespace App\Plan\Push\Entity;

use App\Entity\TelegramUser;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Підписка браузера на Web Push (один рядок — один пристрій/браузер людини).
 * Push-сервіс відповів 404/410 — підписку видаляємо; інші збої рахуємо.
 */
#[ORM\Entity]
#[ORM\Table(name: 'push_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_push_subscription_endpoint', columns: ['endpoint'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'push_subscription_id_seq')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private TelegramUser $user;

    #[ORM\Column(type: Types::STRING, length: 1000)]
    private string $endpoint;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $p256dh;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $auth;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $ua = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_ok_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastOkAt = null;

    #[ORM\Column(name: 'failed_count', type: Types::INTEGER, options: ['default' => 0])]
    private int $failedCount = 0;

    public function __construct(TelegramUser $user, string $endpoint, string $p256dh, string $auth, ?string $ua)
    {
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->p256dh = $p256dh;
        $this->auth = $auth;
        $this->ua = $ua !== null ? mb_substr($ua, 0, 255) : null;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): TelegramUser
    {
        return $this->user;
    }

    /** Той самий браузер перепідписався іншою людиною (спільний комп'ютер) — підписка переходить до неї. */
    public function reassign(TelegramUser $user, string $p256dh, string $auth, ?string $ua): void
    {
        $this->user = $user;
        $this->p256dh = $p256dh;
        $this->auth = $auth;
        $this->ua = $ua !== null ? mb_substr($ua, 0, 255) : null;
        $this->failedCount = 0;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getP256dh(): string
    {
        return $this->p256dh;
    }

    public function getAuth(): string
    {
        return $this->auth;
    }

    public function getUa(): ?string
    {
        return $this->ua;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastOkAt(): ?DateTimeImmutable
    {
        return $this->lastOkAt;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function delivered(): void
    {
        $this->lastOkAt = new DateTimeImmutable();
        $this->failedCount = 0;
    }

    public function failed(): void
    {
        ++$this->failedCount;
    }
}
