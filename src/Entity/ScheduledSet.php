<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\ScheduledSetRepository;
use App\Service\ScheduleCarService;
use App\Validator\ScheduleLimit;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints\NotBlank;

#[ORM\Entity(repositoryClass: ScheduledSetRepository::class)]
#[ORM\Index(name: "unique_set", columns: ["telegram_user_id", "year", "month", "day", "hour", "car_id"], options: ['unique' => true])]
#[UniqueEntity(fields: ["telegramUserId", "year", "month", "day", "hour", "car"], message: 'Хтось вже забронював. Оберіть інший час')]
#[ORM\HasLifecycleCallbacks()]
#[ScheduleLimit]
class ScheduledSet
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[NotBlank]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $year;

    #[NotBlank]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $month;

    #[NotBlank]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $day;

    #[NotBlank]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $hour;

    #[NotBlank]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $scheduledAt;

    #[NotBlank]
    #[ORM\ManyToOne(targetEntity: TelegramUser::class, inversedBy: 'scheduledSet')]
    #[ORM\JoinColumn(name: 'telegram_user_id', referencedColumnName: 'id')]
    private TelegramUser $telegramUserId;

    #[NotBlank]
    #[ORM\ManyToOne(targetEntity: Car::class, inversedBy: 'scheduledSet')]
    #[ORM\JoinColumn(name: 'car_id', referencedColumnName: 'id')]
    private Car $car;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelegramUserId(): TelegramUser
    {
        return $this->telegramUserId;
    }

    public function setTelegramUserId(TelegramUser $telegramUserId): ScheduledSet
    {
        $this->telegramUserId = $telegramUserId;

        return $this;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function setYear(int $year): ScheduledSet
    {
        $this->year = $year;

        return $this;
    }

    public function getMonth(): int
    {
        return $this->month;
    }

    public function setMonth(int $month): ScheduledSet
    {
        $this->month = $month;

        return $this;
    }

    public function getDay(): int
    {
        return $this->day;
    }

    public function setDay(int $day): ScheduledSet
    {
        $this->day = $day;

        return $this;
    }

    public function getHour(): int
    {
        return $this->hour;
    }

    public function setHour(int $hour): ScheduledSet
    {
        $this->hour = $hour;

        return $this;
    }

    public function getScheduledAt(): \DateTime
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(\DateTime $scheduledAt): ScheduledSet
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    public function getCar(): Car
    {
        return $this->car;
    }

    public function setCar(Car $car): ScheduledSet
    {
        $this->car = $car;

        return $this;
    }


    public function getScheduledDateTime(): \DateTime
    {
        $scheduledByCurrentUserDate = ScheduleCarService::createNewDate();
        $scheduledByCurrentUserDate->setDate($this->getYear(), $this->getMonth(), $this->getDay());
        $scheduledByCurrentUserDate->setTime($this->getHour(),0);

        return $scheduledByCurrentUserDate;
    }
}
