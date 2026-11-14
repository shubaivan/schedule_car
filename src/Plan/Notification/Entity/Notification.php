<?php

namespace App\Plan\Notification\Entity;

use App\Entity\TelegramUser;
use App\Plan\EventStore\Entity\PlanEventRow;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Рядок центру сповіщень: подія → людина. Таблицю створює S0, веде
 * NotificationFanout (S1); доставки в Telegram / Web Push позначаються тут же.
 * event_group — група саме для цієї людини (одна подія: учаснику «статус»,
 * директору «потрібне рішення»; згаданому — «вас згадали»).
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'idx_notification_inbox', columns: ['user_id', 'read_at', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_notification_user_event', columns: ['user_id', 'event_id'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'notification_id_seq')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private TelegramUser $user;

    #[ORM\ManyToOne(targetEntity: PlanEventRow::class)]
    #[ORM\JoinColumn(name: 'event_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PlanEventRow $event;

    #[ORM\Column(name: 'event_group', type: Types::STRING, length: 16, options: ['default' => ''])]
    private string $eventGroup = '';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $readAt = null;

    #[ORM\Column(name: 'delivered_tg_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deliveredTgAt = null;

    #[ORM\Column(name: 'delivered_push_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deliveredPushAt = null;

    public function __construct(TelegramUser $user, PlanEventRow $event, DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->event = $event;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id !== null ? (int) $this->id : null;
    }

    public function getUser(): TelegramUser
    {
        return $this->user;
    }

    public function getEvent(): PlanEventRow
    {
        return $this->event;
    }

    public function getEventGroup(): string
    {
        return $this->eventGroup;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function getDeliveredTgAt(): ?DateTimeImmutable
    {
        return $this->deliveredTgAt;
    }

    public function getDeliveredPushAt(): ?DateTimeImmutable
    {
        return $this->deliveredPushAt;
    }
}
