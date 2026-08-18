<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\CarRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints\NotBlank;

#[ORM\Entity(repositoryClass: CarRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class Car
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $carNumber = null;

    /** Марка й модель: «Renault Master», щоб у списку не було самих номерів. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $model = null;

    /** Продана або в ремонті — прибираємо з вибору, історію лишаємо. */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\OneToMany(targetEntity: ScheduledSet::class, mappedBy: 'car', cascade: ['persist'])]
    private Collection $scheduledSet;

    /**
     * @var Collection|ArrayCollection|CarDriver[]
     */
    #[NotBlank]
    #[ORM\OneToMany(targetEntity: CarDriver::class, mappedBy: 'car')]
    private Collection $carDriver;

    public function __construct()
    {
        $this->scheduledSet = new ArrayCollection();
        $this->carDriver = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCarNumber(): ?string
    {
        return $this->carNumber;
    }

    public function setCarNumber(string $carNumber): static
    {
        $this->carNumber = $carNumber;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /** Як показувати машину людині: «AA1234BB · Renault Master». */
    public function label(): string
    {
        return $this->model !== null && $this->model !== ''
            ? sprintf('%s · %s', (string) $this->carNumber, $this->model)
            : (string) $this->carNumber;
    }

    public function getScheduledSet(): Collection
    {
        return $this->scheduledSet;
    }

    public function setScheduledSet(Collection $scheduledSet): void
    {
        $this->scheduledSet = $scheduledSet;
    }

    /**
     * @return Collection|CarDriver[]
     */
    public function getCarDriver(): Collection
    {
        return $this->carDriver;
    }

    public function setCarDriver(Collection $carDriver): void
    {
        $this->carDriver = $carDriver;
    }
}
