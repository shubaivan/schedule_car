<?php

namespace App\Tasks\Entity;

use App\Entity\TelegramUser;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * До якого повідомлення людина дочитала задачу.
 * Звідси лічильники непрочитаного і колонка «Хроніка» (задачі, які людина відкривала).
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_read')]
#[ORM\UniqueConstraint(name: 'uniq_task_read', columns: ['task_id', 'user_id'])]
class TaskRead
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private TelegramUser $user;

    #[ORM\Column(name: 'last_message_id', type: 'integer', options: ['default' => 0])]
    private int $lastMessageId = 0;

    #[ORM\Column(name: 'read_at', type: Types::DATETIME_MUTABLE)]
    private DateTime $readAt;

    public function __construct(Task $task, TelegramUser $user)
    {
        $this->task = $task;
        $this->user = $user;
        $this->readAt = Task::now();
    }

    public function getLastMessageId(): int
    {
        return $this->lastMessageId;
    }

    public function readUpTo(int $messageId): self
    {
        $this->lastMessageId = max($this->lastMessageId, $messageId);
        $this->readAt = Task::now();

        return $this;
    }
}
