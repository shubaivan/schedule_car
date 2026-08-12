<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Enum\AccessStatus;
use App\Repository\TelegramUserRepository;
use Doctrine\DBAL\Types\Types;
use App\Supply\Entity\Department;
use App\Supply\Enum\SupplyRole;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Один запис на людину: і для бота, і для входу в CRM.
 * Логін/пароль заповнені лише в менеджерів — робітники живуть тільки в Telegram.
 */
#[ORM\Entity(repositoryClass: TelegramUserRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class TelegramUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Значення за замовчуванням обов'язкові: Telegram не присилає username чи
    // прізвище в кожного, а звернення до неініціалізованої типізованої властивості — фатальна помилка.
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $chatId = null;
    #[ORM\Column(type: 'string', length: 255, unique: true, nullable: false)]
    private ?string $telegram_id = null;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $phone_number = null;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $first_name = null;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $last_name = null;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $username = null;
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $language_code = 'uk';

    #[ORM\OneToMany(targetEntity: ScheduledSet::class, mappedBy: 'telegramUserId', cascade: ["persist"])]
    private Collection $scheduledSet;

    #[NotBlank]
    #[ORM\OneToMany(targetEntity: CarDriver::class, mappedBy: 'car')]
    private Collection $carDriver;

    #[ORM\Column(name: 'supply_role', type: 'string', length: 16, enumType: SupplyRole::class, nullable: false, options: ['default' => 'worker'])]
    private SupplyRole $supplyRole = SupplyRole::Worker;

    #[ORM\Column(name: 'access_status', type: 'string', length: 16, enumType: AccessStatus::class, nullable: false, options: ['default' => 'pending'])]
    private AccessStatus $accessStatus = AccessStatus::Pending;

    #[ORM\Column(name: 'access_decided_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $accessDecidedAt = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'access_decided_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $accessDecidedBy = null;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', referencedColumnName: 'id', nullable: true)]
    private ?Department $department = null;


    public function __construct()
    {
        $this->scheduledSet = new ArrayCollection();
        $this->carDriver = new ArrayCollection();
        $this->phone_number = null;
        $this->chatId = null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): TelegramUser
    {
        $this->id = $id;

        return $this;
    }

    public function getTelegramId(): ?string
    {
        return $this->telegram_id;
    }

    public function setTelegramId(?string $telegram_id): TelegramUser
    {
        $this->telegram_id = $telegram_id;

        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phone_number;
    }

    public function setPhoneNumber(?string $phone_number): TelegramUser
    {
        $this->phone_number = $phone_number;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->first_name;
    }

    public function setFirstName(?string $first_name): TelegramUser
    {
        $this->first_name = $first_name;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->last_name;
    }

    public function setLastName(?string $last_name): TelegramUser
    {
        $this->last_name = $last_name;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): TelegramUser
    {
        $this->username = $username;

        return $this;
    }

    public function getLanguageCode(): string
    {
        return $this->language_code;
    }

    public function setLanguageCode(string $language_code): TelegramUser
    {
        $this->language_code = $language_code;

        return $this;
    }

    public function getChatId(): ?string
    {
        return $this->chatId;
    }

    public function setChatId(?string $chatId): TelegramUser
    {
        $this->chatId = $chatId;

        return $this;
    }

    public function getScheduledSet(): Collection
    {
        return $this->scheduledSet;
    }

    public function setScheduledSet(Collection $scheduledSet): void
    {
        $this->scheduledSet = $scheduledSet;
    }

    public function getCarDriver(): Collection
    {
        return $this->carDriver;
    }

    public function setCarDriver(Collection $carDriver): void
    {
        $this->carDriver = $carDriver;
    }

    public function concatNameInfo(): string
    {
        return sprintf('%s %s', $this->phone_number, $this->first_name);
    }

    public function getSupplyRole(): SupplyRole
    {
        return $this->supplyRole;
    }

    public function setSupplyRole(SupplyRole $supplyRole): TelegramUser
    {
        $this->supplyRole = $supplyRole;

        return $this;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): TelegramUser
    {
        $this->department = $department;

        return $this;
    }

    public function getAccessStatus(): AccessStatus
    {
        return $this->accessStatus;
    }

    public function isApproved(): bool
    {
        return $this->accessStatus === AccessStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->accessStatus === AccessStatus::Rejected;
    }

    /** Рішення менеджера по доступу. $by === null — автоматичне (телефон у списку менеджерів). */
    public function decideAccess(AccessStatus $status, ?TelegramUser $by): TelegramUser
    {
        $this->accessStatus = $status;
        $this->accessDecidedBy = $by;
        $this->accessDecidedAt = $status === AccessStatus::Pending
            ? null
            : new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));

        return $this;
    }

    public function getAccessDecidedAt(): ?\DateTime
    {
        return $this->accessDecidedAt;
    }

    public function getAccessDecidedBy(): ?TelegramUser
    {
        return $this->accessDecidedBy;
    }

    /** «Іван Петренко (@ivan)» або телефон, якщо імені немає. */
    public function displayName(): string
    {
        $name = trim(sprintf('%s %s', $this->first_name ?? '', $this->last_name ?? ''));

        if ($name === '') {
            $name = $this->username ? '@' . $this->username : ($this->phone_number ?? '—');
        } elseif ($this->username) {
            $name .= ' (@' . $this->username . ')';
        }

        return $name;
    }

    /** Вхід у CRM — лише через бота, тож ідентифікатор користувача це його telegram_id. */
    public function getUserIdentifier(): string
    {
        return (string)$this->telegram_id;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER', $this->supplyRole->securityRole()];
    }

    /** Паролів немає: автентифікація йде одноразовим посиланням із бота. */
    public function getPassword(): ?string
    {
        return null;
    }

    public function eraseCredentials(): void
    {
    }
}
