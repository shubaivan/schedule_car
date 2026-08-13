<?php

namespace App\Supply\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Entity\TelegramUser;
use App\Supply\Enum\SupplyRole;
use App\Supply\Repository\StaffPhoneRepository;
use DateTime;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Довідник телефонів: хто ким буде, щойно зареєструється в боті.
 *
 * Раніше ролі роздавав список у змінних оточення — щоб додати директора,
 * треба було лізти в `.env.local` на сервері. Тепер запис живе в базі:
 * менеджер вносить номер Наталії Григорівни, позначає «Директор», і роль
 * видається автоматично — і якщо людина зареєструється завтра, і якщо вона
 * вже в боті (тоді роль застосується одразу при збереженні запису).
 */
#[ORM\Entity(repositoryClass: StaffPhoneRepository::class)]
#[ORM\Table(name: 'supply_staff_phone')]
// Один номер — один запис. Порівнюємо за хвостом: той самий телефон приходить
// як 380671112233, +380671112233 і 0671112233.
#[ORM\UniqueConstraint(name: 'supply_staff_phone_tail_idx', columns: ['tail'])]
#[ORM\HasLifecycleCallbacks]
class StaffPhone
{
    use CreatedUpdatedAtAwareTrait;

    /** Стільки цифр порівнюємо: коду країни й нуля попереду може не бути. */
    public const TAIL_LENGTH = 9;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Як записали — лише цифри, щоб було що показати людині. */
    #[ORM\Column(type: 'string', length: 32, nullable: false)]
    private string $phone;

    #[ORM\Column(type: 'string', length: 16, nullable: false)]
    private string $tail;

    /** Чий це номер: «Наталія Григорівна, директор». */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', length: 16, enumType: SupplyRole::class, nullable: false)]
    private SupplyRole $role = SupplyRole::Worker;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $createdBy = null;

    /** Кому роль уже видано — видно, що запис спрацював, а не чекає. */
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'applied_to_id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $appliedTo = null;

    #[ORM\Column(name: 'applied_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTime $appliedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new DateTime('now', new DateTimeZone('Europe/Kyiv'));
        $this->setCreatedAt($now);
        $this->setUpdatedAt($now);
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->setUpdatedAt(new DateTime('now', new DateTimeZone('Europe/Kyiv')));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getTail(): string
    {
        return $this->tail;
    }

    public function setTail(string $tail): self
    {
        $this->tail = $tail;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getRole(): SupplyRole
    {
        return $this->role;
    }

    public function setRole(SupplyRole $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function getCreatedBy(): ?TelegramUser
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?TelegramUser $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getAppliedTo(): ?TelegramUser
    {
        return $this->appliedTo;
    }

    public function getAppliedAt(): ?DateTime
    {
        return $this->appliedAt;
    }

    public function markApplied(TelegramUser $user): self
    {
        $this->appliedTo = $user;
        $this->appliedAt = new DateTime('now', new DateTimeZone('Europe/Kyiv'));

        return $this;
    }
}
