<?php

namespace App\Plan\Search\Entity;

use App\Entity\TelegramUser;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Збережений пошук людини: назва + рядок запиту адреси (`q=…&type=supply&author=5`). */
#[ORM\Entity]
#[ORM\Table(name: 'plan_saved_search')]
#[ORM\Index(name: 'idx_plan_saved_search_user', columns: ['user_id', 'id'])]
class SavedSearch
{
    public const int MAX_PER_USER = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'plan_saved_search_id_seq')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private TelegramUser $user;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 1000)]
    private string $query;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(TelegramUser $user, string $name, string $query)
    {
        $this->user = $user;
        $this->name = $name;
        $this->query = $query;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): TelegramUser
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
