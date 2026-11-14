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
 */
#[ORM\Entity(repositoryClass: TaskMessageRepository::class)]
#[ORM\Table(name: 'task_message')]
#[ORM\Index(columns: ['task_id', 'id'], name: 'idx_task_message_task')]
class TaskMessage
{
    public const KIND_COMMENT = 'comment';
    public const KIND_EVENT = 'event';

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
        $this->deletedAt = Task::now();

        return $this;
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
