<?php

namespace App\Warehouse\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Рядок руху: яка позиція і скільки. Для поштучної — завжди 1. */
#[ORM\Entity]
#[ORM\Table(name: 'wh_movement_line')]
#[ORM\Index(name: 'wh_movement_line_item_idx', columns: ['item_id'])]
class WhMovementLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WhMovement::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'movement_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private WhMovement $movement;

    #[ORM\ManyToOne(targetEntity: WhItem::class)]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private WhItem $item;

    #[ORM\Column(type: 'integer', nullable: false)]
    private int $quantity = 1;

    /** Ставка оренди за добу за одиницю — якщо майно поїхало в оренду. */
    #[ORM\Column(name: 'rental_rate', type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $rentalRate = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMovement(): WhMovement
    {
        return $this->movement;
    }

    public function setMovement(WhMovement $movement): self
    {
        $this->movement = $movement;

        return $this;
    }

    public function getItem(): WhItem
    {
        return $this->item;
    }

    public function setItem(WhItem $item): self
    {
        $this->item = $item;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getRentalRate(): ?string
    {
        return $this->rentalRate;
    }

    public function setRentalRate(?string $rentalRate): self
    {
        $this->rentalRate = $rentalRate;

        return $this;
    }
}
