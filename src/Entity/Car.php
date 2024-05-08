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

    #[ORM\OneToMany(targetEntity: ScheduledSet::class, mappedBy: 'car', cascade: ["persist"])]
    private Collection $scheduledSet;

    /**
     * @var Collection|ArrayCollection|CarDriver[]
     */
    #[NotBlank]
    #[ORM\OneToMany(targetEntity: CarDriver::class, mappedBy: 'car')]
    private Collection $carDriver;

    public function __construct() {
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

    public function getCarInfo(): string
    {
        $info[] = $this->getCarNumber();
        foreach ($this->carDriver as $driver) {
            $info[] = $driver->getDriver()->concatNameInfo();
        }

        return implode(';', $info);
    }
}
