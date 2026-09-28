<?php

namespace App\Warehouse\Entity;

use App\Entity\TelegramUser;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Repository\WhActivityRepository;
use DateTime;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Журнал складу: хто, що, коли і звідки.
 *
 * Фіксується все: скан наклейки, перехід за посиланням із бота, відкриття
 * картки в адмінці, кожна зміна. На дороге майно, що їздить між клієнтами,
 * питання «хто його бачив останнім і хто що міняв» — основне, і відповідь
 * на нього має бути одним запитом.
 *
 * Об'єкт запису — пара «тип + id» і підпис на момент дії: картку можуть
 * перейменувати чи прибрати, а запис має лишитись читабельним.
 */
#[ORM\Entity(repositoryClass: WhActivityRepository::class)]
#[ORM\Table(name: 'wh_activity')]
#[ORM\Index(name: 'wh_activity_subject_idx', columns: ['subject_type', 'subject_id'])]
#[ORM\Index(name: 'wh_activity_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'wh_activity_at_idx', columns: ['at'])]
class WhActivity
{
    public const CHANNEL_BOT = 'bot';
    public const CHANNEL_ADMIN = 'admin';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 16, enumType: ActivityAction::class, nullable: false)]
    private ActivityAction $action;

    /** item | client | site | movement | category */
    #[ORM\Column(name: 'subject_type', type: 'string', length: 16, nullable: false)]
    private string $subjectType;

    #[ORM\Column(name: 'subject_id', type: 'integer', nullable: false)]
    private int $subjectId;

    #[ORM\Column(name: 'subject_label', type: 'string', length: 255, nullable: false)]
    private string $subjectLabel;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $user;

    /** bot — з Telegram (скан, посилання); admin — з адмінки. */
    #[ORM\Column(type: 'string', length: 8, nullable: false)]
    private string $channel;

    /** Подробиці: що саме змінили, куди поїхало, яку сторінку відкрили. */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $details;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private DateTime $at;

    public function __construct(
        ActivityAction $action,
        string $subjectType,
        int $subjectId,
        string $subjectLabel,
        ?TelegramUser $user,
        string $channel,
        ?string $details = null,
    ) {
        $this->action = $action;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->subjectLabel = mb_substr($subjectLabel, 0, 255);
        $this->user = $user;
        $this->channel = $channel;
        $this->details = $details !== null ? mb_substr($details, 0, 500) : null;
        $this->at = new DateTime('now', new DateTimeZone('Europe/Kyiv'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAction(): ActivityAction
    {
        return $this->action;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): int
    {
        return $this->subjectId;
    }

    public function getSubjectLabel(): string
    {
        return $this->subjectLabel;
    }

    public function getUser(): ?TelegramUser
    {
        return $this->user;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function getAt(): DateTime
    {
        return $this->at;
    }
}
