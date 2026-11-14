<?php

namespace App\Plan\EventStore\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * «Цей споживач цю подію вже взяв» — захист від повторної доставки черги
 * (воркер упав після Telegram, але до ack): той самий FanOut удруге не дійде
 * до споживача. Пише лише FanOutHandler (INSERT … ON CONFLICT DO NOTHING).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'plan_event_consumed')]
class PlanEventConsumed
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: PlanEventRow::class)]
    #[ORM\JoinColumn(name: 'event_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PlanEventRow $event;

    #[ORM\Id]
    #[ORM\Column(length: 120)]
    private string $consumer;

    #[ORM\Column(name: 'consumed_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $consumedAt;

    public function __construct(PlanEventRow $event, string $consumer, DateTimeImmutable $consumedAt)
    {
        $this->event = $event;
        $this->consumer = $consumer;
        $this->consumedAt = $consumedAt;
    }

    public function getEvent(): PlanEventRow
    {
        return $this->event;
    }

    public function getConsumer(): string
    {
        return $this->consumer;
    }

    public function getConsumedAt(): DateTimeImmutable
    {
        return $this->consumedAt;
    }
}
