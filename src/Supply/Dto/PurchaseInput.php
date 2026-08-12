<?php

namespace App\Supply\Dto;

use App\Supply\Entity\Supplier;
use App\Supply\Enum\PaymentType;

/**
 * Що вводить менеджер, закриваючи заявку покупкою.
 *
 * Достатньо або суми, або ціни з кількістю — RecordPurchase дорахує решту.
 */
class PurchaseInput
{
    public function __construct(
        public Supplier $supplier,
        public ?string $totalAmount = null,
        public ?string $quantity = null,
        public ?string $pricePerUnit = null,
        public PaymentType $payment = PaymentType::Bank,
        public bool $vatIncluded = true,
        public ?string $invoiceNumber = null,
        public ?\DateTime $purchasedAt = null,
    ) {
    }
}
