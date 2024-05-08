<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\TelegramUserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints\NotBlank;

#[ORM\Entity(repositoryClass: TelegramUserRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class TelegramUser
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $chatId;
    #[ORM\Column(type: 'string', length: 255, unique: true, nullable: false)]
    private ?string $telegram_id;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $phone_number;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $first_name;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $last_name;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $username;
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $language_code;

    #[ORM\OneToMany(targetEntity: ScheduledSet::class, mappedBy: 'telegramUserId', cascade: ["persist"])]
    private Collection $scheduledSet;

    #[NotBlank]
    #[ORM\OneToMany(targetEntity: CarDriver::class, mappedBy: 'car')]
    private Collection $carDriver;

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
        return sprintf('%s %s %s %s', $this->phone_number, $this->first_name, $this->last_name, $this->username);
    }
}
