<?php

namespace App\Warehouse\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Entity\TelegramUser;
use App\Warehouse\Enum\MovementType;
use App\Warehouse\Repository\WhMovementRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Один рух майна за одним документом: «відвантажили 40 щитів і 200 замків
 * на ЖК Сонячний за накладною №15».
 *
 * Записаний рух не правиться: помилку виправляють зустрічним рухом, як і в
 * паперовому обліку. Інакше картка позиції й історія розійдуться.
 */
#[ORM\Entity(repositoryClass: WhMovementRepository::class)]
#[ORM\Table(name: 'wh_movement')]
#[ORM\Index(name: 'wh_movement_from_idx', columns: ['from_site_id'])]
#[ORM\Index(name: 'wh_movement_to_idx', columns: ['to_site_id'])]
#[ORM\HasLifecycleCallbacks]
class WhMovement
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 16, enumType: MovementType::class, nullable: false)]
    private MovementType $type = MovementType::Receipt;

    /** Дата за документом, а не дата внесення в систему. */
    #[ORM\Column(name: 'occurred_at', type: Types::DATE_MUTABLE, nullable: false)]
    private DateTime $occurredAt;

    #[ORM\ManyToOne(targetEntity: WhSite::class)]
    #[ORM\JoinColumn(name: 'from_site_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?WhSite $fromSite = null;

    #[ORM\ManyToOne(targetEntity: WhSite::class)]
    #[ORM\JoinColumn(name: 'to_site_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?WhSite $toSite = null;

    /** Номер накладної чи акта — за ним рух шукають у паперах. */
    #[ORM\Column(name: 'document_number', type: 'string', length: 64, nullable: true)]
    private ?string $documentNumber = null;

    /** Для надходження — від кого прийшло. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $counterparty = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $createdBy = null;

    /** @var Collection<int, WhMovementLine> */
    #[ORM\OneToMany(targetEntity: WhMovementLine::class, mappedBy: 'movement', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
        $this->occurredAt = new DateTime('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): MovementType
    {
        return $this->type;
    }

    public function setType(MovementType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getOccurredAt(): DateTime
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(DateTime $occurredAt): self
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getFromSite(): ?WhSite
    {
        return $this->fromSite;
    }

    public function setFromSite(?WhSite $fromSite): self
    {
        $this->fromSite = $fromSite;

        return $this;
    }

    public function getToSite(): ?WhSite
    {
        return $this->toSite;
    }

    public function setToSite(?WhSite $toSite): self
    {
        $this->toSite = $toSite;

        return $this;
    }

    /** Клієнт, якого стосується рух: власник об'єкта «куди», а для повернення — «звідки». */
    public function getClient(): ?WhClient
    {
        return $this->toSite?->getClient() ?? $this->fromSite?->getClient();
    }

    /** Об'єкт (не склад), якого стосується рух, — туди й лягають його документи. */
    public function getSite(): ?WhSite
    {
        foreach ([$this->toSite, $this->fromSite] as $site) {
            if ($site !== null && ! $site->isWarehouse()) {
                return $site;
            }
        }

        return null;
    }

    public function getDocumentNumber(): ?string
    {
        return $this->documentNumber;
    }

    public function setDocumentNumber(?string $documentNumber): self
    {
        $this->documentNumber = $documentNumber;

        return $this;
    }

    public function getCounterparty(): ?string
    {
        return $this->counterparty;
    }

    public function setCounterparty(?string $counterparty): self
    {
        $this->counterparty = $counterparty;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function getCreatedBy(): ?TelegramUser
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?TelegramUser $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /** @return Collection<int, WhMovementLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(WhMovementLine $line): self
    {
        $line->setMovement($this);
        $this->lines->add($line);

        return $this;
    }

    /** «Надходження №15 від 12.09.2026» — підпис руху в історії й на Диску. */
    public function getTitle(): string
    {
        return trim(sprintf(
            '%s%s від %s',
            $this->type->label(),
            $this->documentNumber ? ' №' . $this->documentNumber : '',
            $this->occurredAt->format('d.m.Y'),
        ));
    }
}
