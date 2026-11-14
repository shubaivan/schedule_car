<?php

namespace App\Tasks\Entity;

use App\Entity\TelegramUser;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Історія правок повідомлення (ТЗ «Зв’язок» §20): людина бачить лише
 * позначку «змінено», а попередній текст лишається в базі для аудиту.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_message_edit')]
#[ORM\Index(columns: ['message_id'], name: 'idx_task_message_edit_message')]
class TaskMessageEdit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TaskMessage::class)]
    #[ORM\JoinColumn(name: 'message_id', nullable: false, onDelete: 'CASCADE')]
    private TaskMessage $message;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'editor_id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $editor;

    #[ORM\Column(name: 'old_text', type: Types::TEXT)]
    private string $oldText;

    #[ORM\Column(name: 'edited_at', type: Types::DATETIME_MUTABLE)]
    private DateTime $editedAt;

    public function __construct(TaskMessage $message, ?TelegramUser $editor, string $oldText)
    {
        $this->message = $message;
        $this->editor = $editor;
        $this->oldText = $oldText;
        $this->editedAt = Task::now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): TaskMessage
    {
        return $this->message;
    }

    public function getEditor(): ?TelegramUser
    {
        return $this->editor;
    }

    public function getOldText(): string
    {
        return $this->oldText;
    }

    public function getEditedAt(): DateTime
    {
        return $this->editedAt;
    }
}
