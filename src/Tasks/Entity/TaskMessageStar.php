<?php

namespace App\Tasks\Entity;

use App\Entity\TelegramUser;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** ★ повідомлення — особиста закладка людини (як у ПланФіксі під коментарем). */
#[ORM\Entity]
#[ORM\Table(name: 'task_message_star')]
#[ORM\UniqueConstraint(name: 'uniq_task_message_star', columns: ['user_id', 'message_id'])]
class TaskMessageStar
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TaskMessage::class)]
    #[ORM\JoinColumn(name: 'message_id', nullable: false, onDelete: 'CASCADE')]
    private TaskMessage $message;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private TelegramUser $user;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private DateTime $createdAt;

    public function __construct(TaskMessage $message, TelegramUser $user)
    {
        $this->message = $message;
        $this->user = $user;
        $this->createdAt = Task::now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): TaskMessage
    {
        return $this->message;
    }

    public function getUser(): TelegramUser
    {
        return $this->user;
    }
}
