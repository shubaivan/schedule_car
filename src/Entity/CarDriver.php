<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\CarDriverRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CarDriverRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class CarDriver
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Car::class, inversedBy: 'carDriver')]
    private Car $car;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class, inversedBy: 'carDriver')]
    private TelegramUser $driver;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getCar(): Car
    {
        return $this->car;
    }

    public function setCar(Car $car): void
    {
        $this->car = $car;
    }

    public function getDriver(): TelegramUser
    {
        return $this->driver;
    }

    public function setDriver(TelegramUser $driver): void
    {
        $this->driver = $driver;
    }
}
