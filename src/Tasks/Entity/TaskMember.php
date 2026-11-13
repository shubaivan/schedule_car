<?php

namespace App\Tasks\Entity;

use App\Entity\TelegramUser;
use App\Supply\Entity\Department;
use Doctrine\ORM\Mapping as ORM;

/** Учасник задачі. $via — відділ, через який людину додали (для показу). */
#[ORM\Entity]
#[ORM\Table(name: 'task_member')]
#[ORM\UniqueConstraint(name: 'uniq_task_member', columns: ['task_id', 'user_id'])]
class TaskMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'members')]
    #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private TelegramUser $user;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'via_department_id', nullable: true, onDelete: 'SET NULL')]
    private ?Department $via;

    public function __construct(Task $task, TelegramUser $user, ?Department $via = null)
    {
        $this->task = $task;
        $this->user = $user;
        $this->via = $via;
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

    public function getVia(): ?Department
    {
        return $this->via;
    }
}
