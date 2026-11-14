<?php

namespace App\Tasks\Entity;

use App\Entity\TelegramUser;
use App\Supply\Entity\Department;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Repository\TaskRepository;
use DateTime;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SortDirection;

/**
 * Угода/задача («прецедент» — звернення клієнта до заводу).
 *
 * Уся робота живе в чаті задачі (TaskMessage) з файлами й превʼю.
 * Учасники — люди; вибір відділу додає всіх його людей (як у ПланФіксі),
 * а сам відділ запамʼятовується, щоб було видно, кому адресовано.
 */
#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'task')]
#[ORM\Index(columns: ['status'], name: 'idx_task_status')]
#[ORM\Index(columns: ['last_activity_at'], name: 'idx_task_activity')]
class Task
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Контрагент: клієнт, обʼєкт, замовник. Поки рядком — довідник контактів буде окремо. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $client = null;

    #[ORM\Column(type: 'string', length: 16, enumType: TaskStatus::class, options: ['default' => 'new'])]
    private TaskStatus $status = TaskStatus::New;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'creator_id', nullable: false)]
    private TelegramUser $creator;

    #[ORM\Column(name: 'due_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTime $dueAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private DateTime $createdAt;

    #[ORM\Column(name: 'last_activity_at', type: Types::DATETIME_MUTABLE)]
    private DateTime $lastActivityAt;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTime $closedAt = null;

    /** @var Collection<int, TaskMember> */
    #[ORM\OneToMany(targetEntity: TaskMember::class, mappedBy: 'task', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $members;

    /** @var Collection<int, Department> */
    #[ORM\ManyToMany(targetEntity: Department::class)]
    #[ORM\JoinTable(name: 'task_department')]
    #[ORM\JoinColumn(name: 'task_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'department_id', onDelete: 'CASCADE')]
    private Collection $departments;

    /** @var Collection<int, TaskLabel> */
    #[ORM\ManyToMany(targetEntity: TaskLabel::class)]
    #[ORM\JoinTable(name: 'task_task_label')]
    #[ORM\JoinColumn(name: 'task_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'label_id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['sort' => SortDirection::Ascending])]
    private Collection $labels;

    public function __construct(string $title, TelegramUser $creator)
    {
        $this->title = $title;
        $this->creator = $creator;
        $this->createdAt = self::now();
        $this->lastActivityAt = $this->createdAt;
        $this->members = new ArrayCollection();
        $this->departments = new ArrayCollection();
        $this->labels = new ArrayCollection();
    }

    public static function now(): DateTime
    {
        return new DateTime('now', new DateTimeZone('Europe/Kyiv'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getClient(): ?string
    {
        return $this->client;
    }

    public function setClient(?string $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getStatus(): TaskStatus
    {
        return $this->status;
    }

    public function setStatus(TaskStatus $status): self
    {
        $this->status = $status;
        $this->closedAt = $status->isClosed() ? self::now() : null;

        return $this;
    }

    public function getCreator(): TelegramUser
    {
        return $this->creator;
    }

    public function getDueAt(): ?DateTime
    {
        return $this->dueAt;
    }

    public function setDueAt(?DateTime $dueAt): self
    {
        $this->dueAt = $dueAt;

        return $this;
    }

    public function isOverdue(): bool
    {
        return $this->dueAt !== null && ! $this->status->isClosed() && $this->dueAt < self::now();
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

    public function getLastActivityAt(): DateTime
    {
        return $this->lastActivityAt;
    }

    public function touch(?DateTime $at = null): self
    {
        $this->lastActivityAt = $at ?? self::now();

        return $this;
    }

    public function getClosedAt(): ?DateTime
    {
        return $this->closedAt;
    }

    /** @return Collection<int, TaskMember> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function hasMember(TelegramUser $user): bool
    {
        foreach ($this->members as $member) {
            if ($member->getUser()->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    public function addMember(TelegramUser $user, ?Department $via = null): self
    {
        if (! $this->hasMember($user)) {
            $this->members->add(new TaskMember($this, $user, $via));
        }

        return $this;
    }

    public function removeMember(TelegramUser $user): self
    {
        foreach ($this->members as $member) {
            if ($member->getUser()->getId() === $user->getId()) {
                $this->members->removeElement($member);
            }
        }

        return $this;
    }

    /** @return Collection<int, Department> */
    public function getDepartments(): Collection
    {
        return $this->departments;
    }

    public function addDepartment(Department $department): self
    {
        if (! $this->departments->contains($department)) {
            $this->departments->add($department);
        }

        return $this;
    }

    public function removeDepartment(Department $department): self
    {
        $this->departments->removeElement($department);

        return $this;
    }

    /** @return Collection<int, TaskLabel> */
    public function getLabels(): Collection
    {
        return $this->labels;
    }

    /** @param iterable<TaskLabel> $labels */
    public function setLabels(iterable $labels): self
    {
        $this->labels->clear();

        foreach ($labels as $label) {
            $this->labels->add($label);
        }

        return $this;
    }

    /** «№ 0042» — номер як у ПланФіксі. */
    public function number(): string
    {
        return sprintf('%04d', (int) $this->id);
    }
}
