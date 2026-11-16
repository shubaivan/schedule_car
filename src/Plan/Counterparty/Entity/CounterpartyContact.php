<?php

namespace App\Plan\Counterparty\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Контактна особа контрагента: «Петро, виконроб, 067…» — у клієнта їх кілька
 * (директор, бухгалтер, виконроб на об'єкті, диспетчер).
 */
#[ORM\Entity]
#[ORM\Table(name: 'counterparty_contact')]
#[ORM\Index(name: 'idx_counterparty_contact_owner', columns: ['counterparty_id'])]
#[ORM\Index(name: 'idx_counterparty_contact_phone', columns: ['phone'])]
class CounterpartyContact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Counterparty::class, inversedBy: 'contacts')]
    #[ORM\JoinColumn(name: 'counterparty_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Counterparty $counterparty;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /** Посада або роль: директор, бухгалтер, виконроб, постачальник. */
    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $role = null;

    /** +380… (PhoneFormat::e164 з плюсом). */
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $email = null;

    /** Основний контакт — перший у списку й у підказках. */
    #[ORM\Column(name: 'is_primary', type: 'boolean', options: ['default' => false])]
    private bool $primary = false;

    /** Порядок у картці (як людина їх розставила у формі). */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $position = 0;

    public function __construct(Counterparty $counterparty, string $name)
    {
        $this->counterparty = $counterparty;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCounterparty(): Counterparty
    {
        return $this->counterparty;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    public function setPrimary(bool $primary): self
    {
        $this->primary = $primary;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }
}
