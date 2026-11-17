<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Enum\AccessStatus;
use App\Repository\TelegramUserRepository;
use App\Supply\Entity\Department;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Enum\WorkRole;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Один запис на людину: і для бота, і для входу в CRM.
 * Пароль — лише в тих, хто працює з робочого місця без Telegram
 * (PASSWORD_LOGIN_ENABLED); решта входить за посиланням із бота.
 */
#[ORM\Entity(repositoryClass: TelegramUserRepository::class)]
#[ORM\HasLifecycleCallbacks()]
#[ORM\UniqueConstraint(name: 'uniq_telegram_user_email', columns: ['email'])]
class TelegramUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
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

    #[ORM\OneToMany(targetEntity: ScheduledSet::class, mappedBy: 'telegramUserId', cascade: ['persist'])]
    private Collection $scheduledSet;

    #[NotBlank]
    #[ORM\OneToMany(targetEntity: CarDriver::class, mappedBy: 'car')]
    private Collection $carDriver;

    #[ORM\Column(name: 'supply_role', type: 'string', length: 16, enumType: SupplyRole::class, nullable: false, options: ['default' => 'worker'])]
    private SupplyRole $supplyRole = SupplyRole::Worker;

    #[ORM\Column(name: 'access_status', type: 'string', length: 16, enumType: AccessStatus::class, nullable: false, options: ['default' => 'pending'])]
    private AccessStatus $accessStatus = AccessStatus::Pending;

    #[ORM\Column(name: 'access_decided_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTime $accessDecidedAt = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'access_decided_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $accessDecidedBy = null;

    /**
     * Прибраний зі списку людей: випадкові реєстрації не мають засмічувати
     * довідник. Рядок лишається, бо на людині може висіти історія заявок.
     */
    #[ORM\Column(name: 'archived_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTime $archivedAt = null;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', referencedColumnName: 'id', nullable: true)]
    private ?Department $department = null;

    /** Хто переніс у кошик: людей не видаляємо назовсім, як у ПланФіксі. */
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'archived_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $archivedBy = null;

    /** Хеш пароля для входу з робочого місця; null — входить лише через бота. */
    #[ORM\Column(name: 'password', type: 'string', length: 255, nullable: true)]
    private ?string $password = null;

    #[ORM\Column(name: 'email', type: 'string', length: 180, nullable: true)]
    private ?string $email = null;

    /** Посада: «Технолог», «Майстер дільниці»… */
    #[ORM\Column(name: 'position', type: 'string', length: 120, nullable: true)]
    private ?string $position = null;

    /** Роль у задачах; null — виводиться з ролі в заявках (WorkRole::fromSupply). */
    #[ORM\Column(name: 'work_role', type: 'string', length: 16, enumType: WorkRole::class, nullable: true)]
    private ?WorkRole $workRole = null;

    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTime $lastSeenAt = null;

    /**
     * Суперадмін «Плану» (журнал аудиту). Видається лише з консолі, в інтерфейсі
     * не редагується; на стендах заявок завжди false.
     */
    #[ORM\Column(name: 'is_superadmin', type: 'boolean', nullable: false, options: ['default' => false])]
    private bool $superadmin = false;

    /**
     * Повний доступ до «Плану» (Олександр, 17.11.2026): усім лишаються Огляд, Хроніка,
     * Задачі, Пошук, Сповіщення й Люди; решта розділів (Постачання, Склад, Контрагенти,
     * Автопарк, Диск, «Звʼязок» до погодження) — лише людям із цим прапорцем і суперадмінам.
     * Видає суперадмін (картка співробітника) або консоль `plan:full-access`. Див. PlanSections.
     */
    #[ORM\Column(name: 'plan_full_access', type: 'boolean', nullable: false, options: ['default' => false])]
    private bool $planFullAccess = false;

    /**
     * Коли людина САМА задала пароль за посиланням із бота (PasswordSetup). На «Плані» вхід
     * за паролем — лише з цією позначкою: старі паролі (задані адміном, консоллю, демо)
     * там не діють.
     */
    #[ORM\Column(name: 'password_set_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $passwordSetAt = null;

    /**
     * «Покоління» входів: +1 — усі сесії й запам'ятовані входи (remember-me, 30 днів) людини
     * на всіх пристроях завершуються («Це був не я» в боті). Див. getRememberMeStamp().
     */
    #[ORM\Column(name: 'session_epoch', type: Types::INTEGER, options: ['default' => 0])]
    private int $sessionEpoch = 0;

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
            : new DateTime('now', new DateTimeZone('Europe/Kyiv'));

        return $this;
    }

    public function getAccessDecidedAt(): ?DateTime
    {
        return $this->accessDecidedAt;
    }

    public function getAccessDecidedBy(): ?TelegramUser
    {
        return $this->accessDecidedBy;
    }

    /** «Іван Петренко (@ivan)» або телефон, якщо імені немає. */
    public function getArchivedAt(): ?DateTime
    {
        return $this->archivedAt;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    public function archive(?TelegramUser $by = null): TelegramUser
    {
        $this->archivedAt = new DateTime('now', new DateTimeZone('Europe/Kyiv'));
        $this->archivedBy = $by;

        return $this;
    }

    public function restore(): TelegramUser
    {
        $this->archivedAt = null;
        $this->archivedBy = null;

        return $this;
    }

    public function getArchivedBy(): ?TelegramUser
    {
        return $this->archivedBy;
    }

    public function setPassword(?string $hash): TelegramUser
    {
        $this->password = $hash;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): TelegramUser
    {
        $this->email = $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null;

        return $this;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function setPosition(?string $position): TelegramUser
    {
        $this->position = $position !== null && trim($position) !== '' ? trim($position) : null;

        return $this;
    }

    public function getWorkRole(): WorkRole
    {
        return $this->workRole ?? WorkRole::fromSupply($this->supplyRole);
    }

    public function setWorkRole(?WorkRole $workRole): TelegramUser
    {
        $this->workRole = $workRole;

        return $this;
    }

    public function getLastSeenAt(): ?DateTime
    {
        return $this->lastSeenAt;
    }

    public function touchLastSeen(): TelegramUser
    {
        $this->lastSeenAt = new DateTime('now', new DateTimeZone('Europe/Kyiv'));

        return $this;
    }

    /** Ім'я без @username — для аватарок і чату. */
    public function shortName(): string
    {
        $name = trim(sprintf('%s %s', $this->first_name ?? '', $this->last_name ?? ''));

        return $name !== '' ? $name : ($this->username ? '@' . $this->username : ($this->phone_number ?? '—'));
    }

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
        return (string) $this->telegram_id;
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER', $this->supplyRole->securityRole()];

        if ($this->superadmin) {
            $roles[] = 'ROLE_PLAN_SUPERADMIN';
        }

        return $roles;
    }

    public function isSuperadmin(): bool
    {
        return $this->superadmin;
    }

    /**
     * Бачить усі задачі заводу, а не лише ті, де бере участь: директор, адмін
     * (за роллю в «Плані») і суперадмін (журнал аудиту має вести в будь-яку задачу).
     */
    public function seesAllTasks(): bool
    {
        return $this->getWorkRole()->seesAllTasks() || $this->superadmin;
    }

    /** Веде співробітників: адміністратор або суперадмін (лише суперадмін змінює суперадмінів і колег-адмінів — StaffDirectory). */
    public function managesStaff(): bool
    {
        return $this->getWorkRole()->managesStaff() || $this->superadmin;
    }

    public function getSessionEpoch(): int
    {
        return $this->sessionEpoch;
    }

    public function bumpSessionEpoch(): static
    {
        ++$this->sessionEpoch;

        return $this;
    }

    /**
     * Підпис запам'ятованого входу (security.yaml → remember_me.signature_properties разом із
     * паролем): зміниться покоління, кошик чи допуск — кука на 30 днів перестає діяти.
     */
    public function getRememberMeStamp(): string
    {
        return $this->sessionEpoch . '|' . ($this->isArchived() ? 'a' : '-') . '|' . ($this->isApproved() ? 'y' : 'n');
    }

    public function getPasswordSetAt(): ?DateTimeImmutable
    {
        return $this->passwordSetAt;
    }

    public function setPasswordSetAt(?DateTimeImmutable $at): static
    {
        $this->passwordSetAt = $at;

        return $this;
    }

    public function hasPlanFullAccess(): bool
    {
        return $this->planFullAccess;
    }

    public function setPlanFullAccess(bool $planFullAccess): static
    {
        $this->planFullAccess = $planFullAccess;

        return $this;
    }

    public function setSuperadmin(bool $superadmin): static
    {
        $this->superadmin = $superadmin;

        return $this;
    }

    /** Хеш пароля робочого місця; у більшості людей його немає — вхід через бота. */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * Сесія та сама, поки та сама людина (id), той самий пароль, ролі й не в кошику.
     * telegram_id навмисно не порівнюємо: прив'язка/відв'язка бота Плану його
     * змінює, і без цього людину викидало б із CRM на всіх пристроях.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self &&
            $user->getId() === $this->id &&
            $user->getPassword() === $this->password &&
            // «Це був не я» (session_epoch) завершує й уже відкриті сесії на всіх пристроях.
            $user->getSessionEpoch() === $this->sessionEpoch &&
            $user->isArchived() === $this->isArchived() &&
            $user->isApproved() === $this->isApproved() &&
            $user->getRoles() === $this->getRoles();
    }
}
