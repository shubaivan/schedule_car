<?php

namespace App\Plan\Zvyazok\Entity;

use App\Entity\TelegramUser;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Чат «Звʼязку» (ТЗ §21, Олександр 15.11): особистий (двоє людей, один на пару —
 * direct_key «менший:більший id») або груповий (назва, учасники, власник).
 * Бачать лише учасники (ChatAccess). Читання/запис — DBAL у ZvyazokService;
 * сутності тримають схему.
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan_chat')]
class Chat
{
    public const string DIRECT = 'direct';
    public const string GROUP = 'group';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'kind', type: Types::STRING, length: 8)]
    private string $kind;

    #[ORM\Column(name: 'title', type: Types::STRING, length: 120, nullable: true)]
    private ?string $title = null;

    /** Особистий чат: «12:40» — щоб пара мала лише один чат (унікальний індекс). */
    #[ORM\Column(name: 'direct_key', type: Types::STRING, length: 32, unique: true, nullable: true)]
    private ?string $directKey = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: false)]
    private TelegramUser $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /** Остання дія в чаті — для сортування лівої колонки. */
    #[ORM\Column(name: 'last_activity_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $lastActivityAt;

    public function __construct(string $kind, TelegramUser $createdBy)
    {
        $this->kind = $kind;
        $this->createdBy = $createdBy;
        $this->createdAt = $this->lastActivityAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
