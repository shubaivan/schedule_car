<?php

namespace App\Supply\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Entity\TelegramUser;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Repository\SupplyRequestRepository;
use DateTime;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Заявка на постачання: одна позиція (арматура, 2 т, до 20.08).
 * Треба три матеріали — три заявки; так простіше і в боті, і в таблиці менеджера.
 */
#[ORM\Entity(repositoryClass: SupplyRequestRepository::class)]
#[ORM\Table(name: 'supply_request')]
#[ORM\Index(name: 'supply_request_status_idx', columns: ['status'])]
#[ORM\Index(name: 'supply_request_author_idx', columns: ['author_id'])]
#[ORM\HasLifecycleCallbacks]
class SupplyRequest
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Людський номер виду 042/2026 — ним заявку називають у чаті й по телефону. */
    #[ORM\Column(type: 'string', length: 16, unique: true, nullable: false)]
    private string $number;

    #[NotBlank]
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false)]
    private TelegramUser $author;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', referencedColumnName: 'id', nullable: true)]
    private ?Department $department = null;

    /** Що потрібно: «Арматура 12 А500С». */
    #[NotBlank]
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $item;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: false)]
    private string $quantity;

    #[ORM\Column(type: 'string', length: 16, enumType: Unit::class, nullable: false)]
    private Unit $unit;

    /** Об'єкт / ділянка, для чого потрібно. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $site = null;

    #[ORM\Column(name: 'need_by', type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTime $needBy = null;

    #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => false])]
    private bool $urgent = false;

    /** Первинний коментар автора при створенні. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: 'string', length: 32, enumType: SupplyStatus::class, nullable: false)]
    private SupplyStatus $status = SupplyStatus::New;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTime $closedAt = null;

    #[ORM\OneToMany(targetEntity: SupplyComment::class, mappedBy: 'request', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['created_at' => 'ASC'])]
    private Collection $comments;

    #[ORM\OneToMany(targetEntity: SupplyStatusLog::class, mappedBy: 'request', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['created_at' => 'ASC'])]
    private Collection $statusLogs;

    /** У кого купили: зазвичай один запис, але буває й кілька постачальників. */
    #[ORM\OneToMany(targetEntity: SupplyPurchase::class, mappedBy: 'request', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['created_at' => 'ASC'])]
    private Collection $purchases;

    /** Накладні, рахунки, договори, фото товару. */
    #[ORM\OneToMany(targetEntity: SupplyAttachment::class, mappedBy: 'request', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['created_at' => 'ASC'])]
    private Collection $attachments;

    public function __construct()
    {
        $this->comments = new ArrayCollection();
        $this->statusLogs = new ArrayCollection();
        $this->purchases = new ArrayCollection();
        $this->attachments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function setNumber(string $number): self
    {
        $this->number = $number;

        return $this;
    }

    public function getAuthor(): TelegramUser
    {
        return $this->author;
    }

    public function setAuthor(TelegramUser $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): self
    {
        $this->department = $department;

        return $this;
    }

    public function getItem(): string
    {
        return $this->item;
    }

    public function setItem(string $item): self
    {
        $this->item = $item;

        return $this;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function setUnit(Unit $unit): self
    {
        $this->unit = $unit;

        return $this;
    }

    public function getSite(): ?string
    {
        return $this->site;
    }

    public function setSite(?string $site): self
    {
        $this->site = $site;

        return $this;
    }

    public function getNeedBy(): ?DateTime
    {
        return $this->needBy;
    }

    public function setNeedBy(?DateTime $needBy): self
    {
        $this->needBy = $needBy;

        return $this;
    }

    public function isUrgent(): bool
    {
        return $this->urgent;
    }

    public function setUrgent(bool $urgent): self
    {
        $this->urgent = $urgent;

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

    public function getStatus(): SupplyStatus
    {
        return $this->status;
    }

    public function setStatus(SupplyStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getClosedAt(): ?DateTime
    {
        return $this->closedAt;
    }

    public function setClosedAt(?DateTime $closedAt): self
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(SupplyComment $comment): self
    {
        if (! $this->comments->contains($comment)) {
            $this->comments->add($comment);
            $comment->setRequest($this);
        }

        return $this;
    }

    public function getStatusLogs(): Collection
    {
        return $this->statusLogs;
    }

    public function addStatusLog(SupplyStatusLog $log): self
    {
        if (! $this->statusLogs->contains($log)) {
            $this->statusLogs->add($log);
            $log->setRequest($this);
        }

        return $this;
    }

    public function getPurchases(): Collection
    {
        return $this->purchases;
    }

    public function addPurchase(SupplyPurchase $purchase): self
    {
        if (! $this->purchases->contains($purchase)) {
            $this->purchases->add($purchase);
            $purchase->setRequest($this);
        }

        return $this;
    }

    public function removePurchase(SupplyPurchase $purchase): self
    {
        $this->purchases->removeElement($purchase);

        return $this;
    }

    /**
     * Скільки всього витрачено за заявкою.
     *
     * Додаємо копійками цілими числами: bcmath є не на кожній машині, а сума
     * float-ів дає класичні 0.1 + 0.2 = 0.30000000000000004.
     */
    public function getPurchaseTotal(): string
    {
        $cents = 0;

        foreach ($this->purchases as $purchase) {
            $cents += (int) round((float) $purchase->getTotalAmount() * 100);
        }

        return number_format($cents / 100, 2, '.', '');
    }

    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(SupplyAttachment $attachment): self
    {
        if (! $this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setRequest($this);
        }

        return $this;
    }

    public function removeAttachment(SupplyAttachment $attachment): self
    {
        $this->attachments->removeElement($attachment);

        return $this;
    }

    /** Заявка вважається закупленою, щойно є хоч один запис із постачальником. */
    public function isPurchased(): bool
    {
        return ! $this->purchases->isEmpty();
    }

    /**
     * «2.5 т», «40 шт» — без хвостових нулів після коми.
     *
     * Обрізати нулі можна ЛИШЕ за наявності крапки: інакше «40» перетворюється
     * на «4» — саме так заявка на 40 мішків показувалась як 4.
     */
    public function getQuantityLabel(): string
    {
        $value = $this->quantity;

        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return ($value === '' ? '0' : $value) . ' ' . $this->unit->label();
    }

    /** Термін вийшов, а заявка ще не закрита. */
    public function isOverdue(): bool
    {
        if ($this->needBy === null || $this->status->isFinal() || $this->status === SupplyStatus::Rejected) {
            return false;
        }

        return $this->needBy < (new DateTime('today', new DateTimeZone('Europe/Kyiv')));
    }
}
