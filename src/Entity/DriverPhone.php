<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\DriverPhoneRepository;
use DateTime;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Довідник водіїв: хто сяде за яку машину, щойно зайде в бота.
 *
 * Той самий підхід, що й у телефонів постачання: керівник автопарку вносить
 * номер і машину заздалегідь, не смикаючи людину. Водій натискає «Старт»,
 * ділиться номером — і одразу бачить, що він водій і за ким закріплений.
 */
#[ORM\Entity(repositoryClass: DriverPhoneRepository::class)]
#[ORM\Table(name: 'driver_phone')]
// Один номер — один водій. Порівнюємо за хвостом: телефон приходить і як
// 380671112233, і як 0671112233.
#[ORM\UniqueConstraint(name: 'driver_phone_tail_idx', columns: ['tail'])]
#[ORM\HasLifecycleCallbacks]
class DriverPhone
{
    use CreatedUpdatedAtAwareTrait;

    /** Стільки цифр порівнюємо — як у довіднику постачання. */
    public const TAIL_LENGTH = 9;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $phone;

    #[ORM\Column(type: 'string', length: 16)]
    private string $tail;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    /** За якою машиною закріплений. Без машини теж можна — підмінний водій. */
    #[ORM\ManyToOne(targetEntity: Car::class)]
    #[ORM\JoinColumn(name: 'car_id', nullable: true, onDelete: 'SET NULL')]
    private ?Car $car = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $createdBy = null;

    /** Кого вже прив'язали — видно, що запис спрацював, а не чекає. */
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

    public function setPhone(string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getTail(): string
    {
        return $this->tail;
    }

    public function setTail(string $tail): static
    {
        $this->tail = $tail;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCar(): ?Car
    {
        return $this->car;
    }

    public function setCar(?Car $car): static
    {
        $this->car = $car;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getCreatedBy(): ?TelegramUser
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?TelegramUser $createdBy): static
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

    public function markApplied(TelegramUser $user): static
    {
        $this->appliedTo = $user;
        $this->appliedAt = new DateTime('now', new DateTimeZone('Europe/Kyiv'));

        return $this;
    }
}
