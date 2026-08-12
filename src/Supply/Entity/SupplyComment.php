<?php

namespace App\Supply\Entity;

use App\Entity\TelegramUser;
use App\Supply\Repository\SupplyCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Коментар у стрічці заявки. Автор і менеджер бачать одну й ту саму стрічку. */
#[ORM\Entity(repositoryClass: SupplyCommentRepository::class)]
#[ORM\Table(name: 'supply_comment')]
#[ORM\Index(name: 'supply_comment_request_idx', columns: ['request_id'])]
class SupplyComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SupplyRequest::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'request_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SupplyRequest $request;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true)]
    private ?TelegramUser $author = null;

    #[NotBlank]
    #[ORM\Column(type: Types::TEXT, nullable: false)]
    private string $text;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $created_at;

    public function __construct()
    {
        $this->created_at = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
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

    public function getAuthor(): ?TelegramUser
    {
        return $this->author;
    }

    public function setAuthor(?TelegramUser $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }
}
