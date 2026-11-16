<?php

namespace App\Plan\Counterparty\Service;

use App\Plan\Counterparty\Enum\CounterpartyKind;
use App\Plan\Counterparty\Enum\LegalForm;
use App\Plan\Counterparty\Enum\PaymentTerms;
use App\Plan\Counterparty\Enum\VatStatus;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Фільтри списку контрагентів — ті самі, що в адресі сторінки
 * (?q=житлобуд&kind=client&vat=payer&manager=12&terms=deferral&archived=1).
 * managerId 0 — «без менеджера».
 */
final readonly class CounterpartyFilter
{
    public function __construct(
        public string $q = '',
        public ?CounterpartyKind $kind = null,
        public ?LegalForm $legalForm = null,
        public ?VatStatus $vatStatus = null,
        public ?PaymentTerms $paymentTerms = null,
        public ?int $managerId = null,
        public bool $archived = false,
    ) {
    }

    /** @param InputBag<string> $query */
    public static function fromQuery(InputBag $query, ?int $me = null): self
    {
        $manager = (string) $query->get('manager', '');

        return new self(
            q: mb_substr(trim((string) $query->get('q', '')), 0, 100),
            // «Обидва» як фасет не показуємо: вони вже в «Клієнтах» і в «Постачальниках».
            kind: match ((string) $query->get('kind', '')) {
                'client' => CounterpartyKind::Client,
                'supplier' => CounterpartyKind::Supplier,
                default => null,
            },
            legalForm: LegalForm::tryFrom((string) $query->get('form', '')),
            vatStatus: VatStatus::tryFrom((string) $query->get('vat', '')),
            paymentTerms: PaymentTerms::tryFrom((string) $query->get('terms', '')),
            managerId: match (true) {
                $manager === 'none' => 0,
                $manager === 'me' => $me,
                ctype_digit($manager) && (int) $manager > 0 => (int) $manager,
                default => null,
            },
            archived: $query->getBoolean('archived'),
        );
    }

    public function with(bool $archived): self
    {
        return new self($this->q, $this->kind, $this->legalForm, $this->vatStatus, $this->paymentTerms, $this->managerId, $archived);
    }
}
