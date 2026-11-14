<?php

namespace App\Plan\EventStore\Entity;

use App\Entity\TelegramUser;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Рядок журналу подій «Плану» (таблиця plan_event).
 *
 * Пише лише PlanEventRecorder — сирим INSERT у тій самій транзакції, що й зміна;
 * сутність потрібна, щоб схема жила в мапінгу Doctrine (schema:validate, diff),
 * і для читання в адмінці. Незмінний: UPDATE/DELETE у коді немає, крім prune.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'plan_event')]
#[ORM\Index(name: 'idx_plan_event_subject', columns: ['subject_type', 'subject_id', 'id'])]
#[ORM\Index(name: 'idx_plan_event_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_plan_event_actor', columns: ['actor_id', 'id'])]
#[ORM\Index(name: 'idx_plan_event_type', columns: ['type', 'id'])]
#[ORM\Index(name: 'idx_plan_event_type_at', columns: ['type', 'occurred_at'])]
// AuditThrottle (S6): «та сама людина × тип × суб'єкт за 10 хв» (міграція Version20261115170000).
#[ORM\Index(name: 'idx_plan_event_dedupe', columns: ['actor_id', 'type', 'subject_type', 'subject_id', 'occurred_at'], options: ['where' => '(actor_id IS NOT NULL)'])]
class PlanEventRow
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'plan_event_id_seq')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\Column(length: 40)]
    private string $type;

    #[ORM\Column(name: 'subject_type', length: 16)]
    private string $subjectType;

    #[ORM\Column(name: 'subject_id')]
    private int $subjectId;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'actor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $actor = null;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $occurredAt;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $payload = [];

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    /** web | bot | cli | api */
    #[ORM\Column(length: 8)]
    private string $channel = 'web';

    public function getId(): ?int
    {
        return $this->id !== null ? (int) $this->id : null;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): int
    {
        return $this->subjectId;
    }

    public function getActor(): ?TelegramUser
    {
        return $this->actor;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }
}
