<?php

namespace App\Plan\Notification\Entity;

use App\Entity\TelegramUser;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Перемикач «канал × група» однієї людини. Рядка немає — увімкнено (дефолт
 * «усе ввімкнено»), тож таблиця тримає лише те, що людина вимкнула або ввімкнула назад.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_pref')]
class NotificationPref
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private TelegramUser $user;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $channel;

    #[ORM\Id]
    #[ORM\Column(name: 'event_group', type: Types::STRING, length: 16)]
    private string $eventGroup;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $enabled = true;

    public function __construct(TelegramUser $user, string $channel, string $eventGroup, bool $enabled)
    {
        $this->user = $user;
        $this->channel = $channel;
        $this->eventGroup = $eventGroup;
        $this->enabled = $enabled;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getEventGroup(): string
    {
        return $this->eventGroup;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
