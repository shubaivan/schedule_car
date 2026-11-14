<?php

namespace App\Tasks\Entity;

use App\Entity\TelegramUser;
use App\Tasks\Repository\TaskMessageRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SortDirection;

/**
 * Повідомлення в чаті задачі. kind = comment (людина написала) або event
 * (система: створено, змінено статус, додано учасників) — усе в одній стрічці.
 *
 * Та сама таблиця — джерело «Хроніки»: кожен рядок — подія задачі
 * (eventType уточнює, яка саме: створено, статус, учасники, закрито).
 * changedAt — коли повідомлення востаннє правили, видаляли чи закріплювали:
 * за ним відкритий чат підтягує зміни старих повідомлень.
 */
#[ORM\Entity(repositoryClass: TaskMessageRepository::class)]
#[ORM\Table(name: 'task_message')]
#[ORM\Index(columns: ['task_id', 'id'], name: 'idx_task_message_task')]
class TaskMessage
{
    public const KIND_COMMENT = 'comment';
    public const KIND_EVENT = 'event';

    public const EVENT_CREATED = 'created';
    public const EVENT_STATUS = 'status';
    public const EVENT_CLOSED = 'closed';
    public const EVENT_MEMBERS = 'members';
    public const EVENT_UPDATED = 'updated';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $author;

    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'comment'])]
    private string $kind;

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $text;

    /** @var list<int> id згаданих людей (@Ім'я) — для колонки «Мої повідомлення». */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $mentions = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private DateTime $createdAt;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTime $deletedAt = null;

    /** Відповідь на повідомлення (цитата над текстом). */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'reply_to_id', nullable: true, onDelete: 'SET NULL')]
    private ?TaskMessage $replyTo = null;

    #[ORM\Column(name: 'event_type', type: 'string', length: 16, nullable: true)]
    private ?string $eventType = null;

    #[ORM\Column(name: 'edited_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTime $editedAt = null;

    #[ORM\Column(name: 'pinned_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTime $pinnedAt = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'pinned_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $pinnedBy = null;

    #[ORM\Column(name: 'changed_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTime $changedAt = null;

    /** @var Collection<int, TaskAttachment> */
    #[ORM\OneToMany(targetEntity: TaskAttachment::class, mappedBy: 'message', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => SortDirection::Ascending])]
    private Collection $attachments;

    /** @param list<int> $mentions */
    public function __construct(Task $task, ?TelegramUser $author, string $text, string $kind = self::KIND_COMMENT, array $mentions = [])
    {
        $this->task = $task;
        $this->author = $author;
        $this->text = $text;
        $this->kind = $kind;
        $this->mentions = $mentions;
        $this->createdAt = Task::now();
        $this->attachments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getAuthor(): ?TelegramUser
    {
        return $this->author;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function isEvent(): bool
    {
        return $this->kind === self::KIND_EVENT;
    }

    public function getText(): string
    {
        return $this->text;
    }

    /** @return list<int> */
    public function getMentions(): array
    {
        return array_values(array_map('intval', $this->mentions));
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTime $at): self
    {
        $this->createdAt = $at;

        return $this;
    }

    public function getDeletedAt(): ?DateTime
    {
        return $this->deletedAt;
    }

    /** Видалене не зникає: у стрічці лишається «повідомлення видалено», файли ховаються. */
    public function delete(): self
    {
        $this->deletedAt = $this->changedAt = Task::now();
        $this->pinnedAt = null;
        $this->pinnedBy = null;

        return $this;
    }

    public function getReplyTo(): ?TaskMessage
    {
        return $this->replyTo;
    }

    public function setReplyTo(?TaskMessage $replyTo): self
    {
        $this->replyTo = $replyTo;

        return $this;
    }

    public function getEventType(): ?string
    {
        return $this->eventType;
    }

    public function setEventType(?string $eventType): self
    {
        $this->eventType = $eventType;

        return $this;
    }

    /** Новий текст; старий зберігає сервіс в історії правок. */
    public function edit(string $text): self
    {
        $this->text = $text;
        $this->editedAt = $this->changedAt = Task::now();

        return $this;
    }

    public function getEditedAt(): ?DateTime
    {
        return $this->editedAt;
    }

    public function pin(?TelegramUser $by): self
    {
        $this->pinnedAt = $this->changedAt = Task::now();
        $this->pinnedBy = $by;

        return $this;
    }

    public function unpin(): self
    {
        $this->pinnedAt = null;
        $this->pinnedBy = null;
        $this->changedAt = Task::now();

        return $this;
    }

    public function getPinnedAt(): ?DateTime
    {
        return $this->pinnedAt;
    }

    public function getPinnedBy(): ?TelegramUser
    {
        return $this->pinnedBy;
    }

    public function getChangedAt(): ?DateTime
    {
        return $this->changedAt;
    }

    /** @return Collection<int, TaskAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(TaskAttachment $attachment): self
    {
        $this->attachments->add($attachment);

        return $this;
    }
}
