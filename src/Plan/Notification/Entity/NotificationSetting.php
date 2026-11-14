<?php

namespace App\Plan\Notification\Entity;

use App\Entity\TelegramUser;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Загальні налаштування сповіщень людини. Рядка немає — дефолти:
 * Telegram і push увімкнено, без тихих годин, без звуку, лише «своє».
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_setting')]
class NotificationSetting
{
    public const string SCOPE_MINE = 'mine';
    /** Директор/адмін: у центрі — події всіх задач і заявок (Telegram і push — лише свої). */
    public const string SCOPE_ALL = 'all';

    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private TelegramUser $user;

    #[ORM\Column(type: Types::STRING, length: 8, options: ['default' => self::SCOPE_MINE])]
    private string $scope = self::SCOPE_MINE;

    /** Telegram-бот Плану взагалі (команда /mute у боті вимикає саме його). */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $telegram = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $push = true;

    /** Звук разом із тостом у відкритому Плані. */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $sound = false;

    /** Тихі години: хвилини від півночі за Києвом; обидва null — вимкнено. */
    #[ORM\Column(name: 'quiet_from', type: Types::SMALLINT, nullable: true)]
    private ?int $quietFrom = null;

    #[ORM\Column(name: 'quiet_to', type: Types::SMALLINT, nullable: true)]
    private ?int $quietTo = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(TelegramUser $user)
    {
        $this->user = $user;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getUser(): TelegramUser
    {
        return $this->user;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): static
    {
        $this->scope = $scope === self::SCOPE_ALL ? self::SCOPE_ALL : self::SCOPE_MINE;

        return $this->touch();
    }

    public function isTelegram(): bool
    {
        return $this->telegram;
    }

    public function setTelegram(bool $telegram): static
    {
        $this->telegram = $telegram;

        return $this->touch();
    }

    public function isPush(): bool
    {
        return $this->push;
    }

    public function setPush(bool $push): static
    {
        $this->push = $push;

        return $this->touch();
    }

    public function isSound(): bool
    {
        return $this->sound;
    }

    public function setSound(bool $sound): static
    {
        $this->sound = $sound;

        return $this->touch();
    }

    public function getQuietFrom(): ?int
    {
        return $this->quietFrom;
    }

    public function getQuietTo(): ?int
    {
        return $this->quietTo;
    }

    /** Обидва null або однакові — тихих годин немає. */
    public function setQuietHours(?int $from, ?int $to): static
    {
        if ($from === null || $to === null || $from === $to) {
            $this->quietFrom = $this->quietTo = null;
        } else {
            $this->quietFrom = max(0, min(1439, $from));
            $this->quietTo = max(0, min(1439, $to));
        }

        return $this->touch();
    }

    private function touch(): static
    {
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }
}
