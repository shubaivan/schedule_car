<?php

namespace App\Warehouse\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Entity\TelegramUser;
use App\Warehouse\Enum\ItemState;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Repository\WhItemRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Картка майна: що це, звідки, скільки коштує і де воно зараз.
 *
 * Для поштучної позиції «де зараз» записано тут же (currentSite) — його
 * оновлює кожен рух, і бот після сканування відповідає одним читанням.
 * Для позиції «кількістю» місць багато одразу, тому залишки рахує WarehouseStock
 * з рядків рухів, а currentSite лишається порожнім.
 */
#[ORM\Entity(repositoryClass: WhItemRepository::class)]
#[ORM\Table(name: 'wh_item')]
#[ORM\Index(name: 'wh_item_site_idx', columns: ['current_site_id'])]
#[ORM\HasLifecycleCallbacks]
class WhItem
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Інвентарний номер: друкується на наклейці поруч із QR, щоб річ знаходили й без телефона. */
    #[ORM\Column(name: 'inventory_number', type: 'string', length: 32, unique: true, nullable: false)]
    private string $inventoryNumber = '';

    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $name = '';

    /** Вид майна з довідника: опалубка, техніка, риштування — що заведуть. */
    #[ORM\ManyToOne(targetEntity: WhCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private WhCategory $category;

    #[ORM\Column(type: 'string', length: 8, enumType: Tracking::class, nullable: false)]
    private Tracking $tracking = Tracking::Unit;

    /** Одиниця для позиції «кількістю»: шт, компл., м². */
    #[ORM\Column(type: 'string', length: 16, nullable: false, options: ['default' => 'шт'])]
    private string $unit = 'шт';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Характеристики, яких у різного майна різний набір: «Розміри» у щита,
     * «Моточаси» й «Держномер» у трактора. Назва → значення, у порядку введення.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON, nullable: false, options: ['default' => '{}'])]
    private array $attributes = [];

    /** Заводський номер — у техніки він є, у щита опалубки зазвичай ні. */
    #[ORM\Column(name: 'serial_number', type: 'string', length: 64, nullable: true)]
    private ?string $serialNumber = null;

    /** Звідки прийшло: постачальник чи виробник, як у накладній. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $supplier = null;

    /** Ціна однієї одиниці при купівлі, грн. */
    #[ORM\Column(name: 'purchase_price', type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $purchasePrice = null;

    #[ORM\Column(name: 'purchased_at', type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTime $purchasedAt = null;

    /** Ставка оренди за добу за одиницю, грн — підставляється у відвантаження. */
    #[ORM\Column(name: 'rental_rate', type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $rentalRate = null;

    #[ORM\Column(type: 'string', length: 16, enumType: ItemState::class, nullable: false, options: ['default' => 'active'])]
    private ItemState $state = ItemState::Active;

    /** Лише для поштучної позиції: де вона зараз. null — ще не надійшла або списана. */
    #[ORM\ManyToOne(targetEntity: WhSite::class)]
    #[ORM\JoinColumn(name: 'current_site_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?WhSite $currentSite = null;

    /** З якого дня позиція там, де вона є. */
    #[ORM\Column(name: 'current_since', type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTime $currentSince = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $createdBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInventoryNumber(): string
    {
        return $this->inventoryNumber;
    }

    public function setInventoryNumber(string $inventoryNumber): self
    {
        $this->inventoryNumber = $inventoryNumber;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getCategory(): WhCategory
    {
        return $this->category;
    }

    public function setCategory(WhCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    /** @return array<string, string> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @param array<string, string> $attributes */
    public function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes;

        return $this;
    }

    public function getTracking(): Tracking
    {
        return $this->tracking;
    }

    public function setTracking(Tracking $tracking): self
    {
        $this->tracking = $tracking;

        return $this;
    }

    public function isUnit(): bool
    {
        return $this->tracking === Tracking::Unit;
    }

    public function getUnit(): string
    {
        return $this->isUnit() ? 'шт' : $this->unit;
    }

    public function setUnit(string $unit): self
    {
        $this->unit = $unit;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(?string $serialNumber): self
    {
        $this->serialNumber = $serialNumber;

        return $this;
    }

    public function getSupplier(): ?string
    {
        return $this->supplier;
    }

    public function setSupplier(?string $supplier): self
    {
        $this->supplier = $supplier;

        return $this;
    }

    public function getPurchasePrice(): ?string
    {
        return $this->purchasePrice;
    }

    public function setPurchasePrice(?string $purchasePrice): self
    {
        $this->purchasePrice = $purchasePrice;

        return $this;
    }

    public function getPurchasedAt(): ?DateTime
    {
        return $this->purchasedAt;
    }

    public function setPurchasedAt(?DateTime $purchasedAt): self
    {
        $this->purchasedAt = $purchasedAt;

        return $this;
    }

    public function getRentalRate(): ?string
    {
        return $this->rentalRate;
    }

    public function setRentalRate(?string $rentalRate): self
    {
        $this->rentalRate = $rentalRate;

        return $this;
    }

    public function getState(): ItemState
    {
        return $this->state;
    }

    public function setState(ItemState $state): self
    {
        $this->state = $state;

        return $this;
    }

    public function getCurrentSite(): ?WhSite
    {
        return $this->currentSite;
    }

    public function getCurrentSince(): ?DateTime
    {
        return $this->currentSince;
    }

    /** Лише рухи змінюють місце — руками його не ставлять, інакше історія розійдеться з карткою. */
    public function placeAt(?WhSite $site, DateTime $since): self
    {
        $this->currentSite = $site;
        $this->currentSince = $site !== null ? $since : null;

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

    public function getLabel(): string
    {
        return sprintf('%s · %s', $this->inventoryNumber, $this->name);
    }
}
