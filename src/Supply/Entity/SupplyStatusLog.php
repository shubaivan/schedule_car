<?php

namespace App\Supply\Entity;

use App\Entity\TelegramUser;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Repository\SupplyStatusLogRepository;
use DateTime;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Аудит: хто, коли і з яким коментарем перевів заявку в новий статус. */
#[ORM\Entity(repositoryClass: SupplyStatusLogRepository::class)]
#[ORM\Table(name: 'supply_status_log')]
#[ORM\Index(name: 'supply_status_log_request_idx', columns: ['request_id'])]
class SupplyStatusLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SupplyRequest::class, inversedBy: 'statusLogs')]
    #[ORM\JoinColumn(name: 'request_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SupplyRequest $request;

    #[ORM\Column(name: 'status_from', type: 'string', length: 32, enumType: SupplyStatus::class, nullable: true)]
    private ?SupplyStatus $statusFrom = null;

    #[ORM\Column(name: 'status_to', type: 'string', length: 32, enumType: SupplyStatus::class, nullable: false)]
    private SupplyStatus $statusTo;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true)]
    private ?TelegramUser $author = null;

    /** Для «Відхилена» — обов'язкова причина, інакше автор не зрозуміє. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, nullable: false)]
    private DateTime $created_at;

    public function __construct()
    {
        $this->created_at = new DateTime('now', new DateTimeZone('Europe/Kyiv'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): SupplyRequest
    {
        return $this->request;
    }

    public function setRequest(SupplyRequest $request): self
    {
        $this->request = $request;

        return $this;
    }

    public function getStatusFrom(): ?SupplyStatus
    {
        return $this->statusFrom;
    }

    public function setStatusFrom(?SupplyStatus $statusFrom): self
    {
        $this->statusFrom = $statusFrom;

        return $this;
    }

    public function getStatusTo(): SupplyStatus
    {
        return $this->statusTo;
    }

    public function setStatusTo(SupplyStatus $statusTo): self
    {
        $this->statusTo = $statusTo;

        return $this;
    }

    public function getAuthor(): ?TelegramUser
    {
        return $this->author;
    }

    public function setAuthor(?TelegramUser $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->created_at;
    }
}
