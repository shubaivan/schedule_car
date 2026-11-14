<?php

namespace App\Plan\Chronicle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Остання подія кожного суб'єкта — для швидкої Хроніки (одна строка на
 * задачу/заявку/позицію). Таблицю створює S0, веде проєкція Хроніки (S4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan_subject_activity')]
#[ORM\Index(name: 'idx_plan_subject_activity_last', columns: ['last_at', 'last_event_id'])]
class PlanSubjectActivity
{
    #[ORM\Id]
    #[ORM\Column(name: 'subject_type', length: 16)]
    private string $subjectType;

    #[ORM\Id]
    #[ORM\Column(name: 'subject_id')]
    private int $subjectId;

    #[ORM\Column(name: 'last_event_id', type: Types::BIGINT)]
    private string $lastEventId;

    #[ORM\Column(name: 'last_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $lastAt;

    public function __construct(string $subjectType, int $subjectId, int $lastEventId, DateTimeImmutable $lastAt)
    {
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->lastEventId = (string) $lastEventId;
        $this->lastAt = $lastAt;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): int
    {
        return $this->subjectId;
    }

    public function getLastEventId(): int
    {
        return (int) $this->lastEventId;
    }

    public function getLastAt(): DateTimeImmutable
    {
        return $this->lastAt;
    }
}
