<?php

namespace App\Plan\Chronicle\Entity;

use App\Entity\TelegramUser;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * До якої події plan_event людина дочитала суб'єкт (заявку, позицію складу…).
 * Задачі й далі читаються через task_read (той самий «+N», що на дошці), тут —
 * усе інше. Пише PlanChronicle атомарним upsert.
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan_read')]
class PlanRead
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private TelegramUser $user;

    #[ORM\Id]
    #[ORM\Column(name: 'subject_type', length: 16)]
    private string $subjectType;

    #[ORM\Id]
    #[ORM\Column(name: 'subject_id')]
    private int $subjectId;

    #[ORM\Column(name: 'last_event_id', type: Types::BIGINT)]
    private string $lastEventId;

    #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $readAt;

    public function __construct(TelegramUser $user, string $subjectType, int $subjectId, int $lastEventId, DateTimeImmutable $readAt)
    {
        $this->user = $user;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->lastEventId = (string) $lastEventId;
        $this->readAt = $readAt;
    }

    public function getLastEventId(): int
    {
        return (int) $this->lastEventId;
    }
}
