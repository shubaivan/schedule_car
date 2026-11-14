<?php

namespace App\Tasks\Entity;

use App\Entity\TelegramUser;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** ★ задачі — особисте для кожної людини (фільтр «Обране» в Хроніці й пошуку). */
#[ORM\Entity]
#[ORM\Table(name: 'task_favorite')]
#[ORM\UniqueConstraint(name: 'uniq_task_favorite', columns: ['user_id', 'task_id'])]
class TaskFavorite
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

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private DateTime $createdAt;

    public function __construct(Task $task, TelegramUser $user)
    {
        $this->task = $task;
        $this->user = $user;
        $this->createdAt = Task::now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getUser(): TelegramUser
    {
        return $this->user;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }
}
