<?php

namespace App\Supply\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Entity\TelegramUser;
use App\Supply\Enum\PaymentType;
use App\Supply\Repository\SupplyPurchaseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Факт закупівлі за заявкою: у кого, скільки, почім.
 *
 * Окрема сутність, а не поля в заявці, бо реальність не вкладається в «один
 * постачальник на заявку»: пісок узяли в одного, доставку в іншого; половину
 * привезли зараз, половину за тиждень. За замовчуванням запис один — форма
 * показує саме його, а «додати ще постачальника» лишається можливістю.
 */
#[ORM\Entity(repositoryClass: SupplyPurchaseRepository::class)]
#[ORM\Table(name: 'supply_purchase')]
#[ORM\Index(name: 'supply_purchase_request_idx', columns: ['request_id'])]
#[ORM\Index(name: 'supply_purchase_supplier_idx', columns: ['supplier_id'])]
#[ORM\HasLifecycleCallbacks]
class SupplyPurchase
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SupplyRequest::class, inversedBy: 'purchases')]
    #[ORM\JoinColumn(name: 'request_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SupplyRequest $request;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'supplier_id', referencedColumnName: 'id', nullable: false)]
    private Supplier $supplier;

    /** Скільки взяли саме в цього постачальника — може бути менше, ніж просили. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    private ?string $quantity = null;

    /**
     * Гроші — лише DECIMAL рядком. float на цінах дає 0.1 + 0.2 = 0.30000000000000004,
     * і сума закупівель за місяць поїде в копійках.
     */
    #[ORM\Column(name: 'price_per_unit', type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    private ?string $pricePerUnit = null;

    #[ORM\Column(name: 'total_amount', type: Types::DECIMAL, precision: 14, scale: 2, nullable: false)]
    private string $totalAmount;

    #[ORM\Column(type: 'string', length: 3, nullable: false, options: ['default' => 'UAH'])]
    private string $currency = 'UAH';

    #[ORM\Column(name: 'vat_included', type: 'boolean', nullable: false, options: ['default' => true])]
    private bool $vatIncluded = true;

    #[ORM\Column(name: 'invoice_number', type: 'string', length: 64, nullable: true)]
    private ?string $invoiceNumber = null;

    #[ORM\Column(name: 'purchased_at', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $purchasedAt = null;

    #[ORM\Column(type: 'string', length: 16, enumType: PaymentType::class, nullable: false)]
    private PaymentType $payment = PaymentType::Bank;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $createdBy = null;

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

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function setSupplier(Supplier $supplier): self
    {
        $this->supplier = $supplier;

        return $this;
    }

    public function getQuantity(): ?string
    {
        return $this->quantity;
    }

    public function setQuantity(?string $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getPricePerUnit(): ?string
    {
        return $this->pricePerUnit;
    }

    public function setPricePerUnit(?string $pricePerUnit): self
    {
        $this->pricePerUnit = $pricePerUnit;

        return $this;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $totalAmount): self
    {
        $this->totalAmount = $totalAmount;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function isVatIncluded(): bool
    {
        return $this->vatIncluded;
    }

    public function setVatIncluded(bool $vatIncluded): self
    {
        $this->vatIncluded = $vatIncluded;

        return $this;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function setInvoiceNumber(?string $invoiceNumber): self
    {
        $invoiceNumber = $invoiceNumber !== null ? trim($invoiceNumber) : null;
        $this->invoiceNumber = $invoiceNumber === '' ? null : $invoiceNumber;

        return $this;
    }

    public function getPurchasedAt(): ?\DateTime
    {
        return $this->purchasedAt;
    }

    public function setPurchasedAt(?\DateTime $purchasedAt): self
    {
        $this->purchasedAt = $purchasedAt;

        return $this;
    }

    public function getPayment(): PaymentType
    {
        return $this->payment;
    }

    public function setPayment(PaymentType $payment): self
    {
        $this->payment = $payment;

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

    /** «12 500,00 ₴» — так суму читають у документах. */
    public function getTotalLabel(): string
    {
        return self::money($this->totalAmount) . ' ' . ($this->currency === 'UAH' ? '₴' : $this->currency);
    }

    public function getPriceLabel(): ?string
    {
        return $this->pricePerUnit !== null ? self::money($this->pricePerUnit) : null;
    }

    public static function money(string $amount): string
    {
        return number_format((float)$amount, 2, ',', ' ');
    }
}
