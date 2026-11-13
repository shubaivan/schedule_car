<?php

namespace App\Tasks\Entity;

use App\Tasks\Repository\TaskLabelRepository;
use Doctrine\ORM\Mapping as ORM;

/** Кольорова мітка задачі: «Терміново», «Чекаємо оплату»… */
#[ORM\Entity(repositoryClass: TaskLabelRepository::class)]
#[ORM\Table(name: 'task_label')]
class TaskLabel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 60, unique: true)]
    private string $name;

    /** HEX-колір, #RRGGBB. */
    #[ORM\Column(type: 'string', length: 7, options: ['default' => '#64748b'])]
    private string $color = '#64748b';

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sort = 0;

    public function __construct(string $name, string $color, int $sort = 0)
    {
        $this->name = $name;
        $this->color = $color;
        $this->sort = $sort;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function getSort(): int
    {
        return $this->sort;
    }
}
