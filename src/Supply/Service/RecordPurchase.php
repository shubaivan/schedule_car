<?php

namespace App\Supply\Service;

use App\Entity\TelegramUser;
use App\Plan\Event\PurchaseRecorded;
use App\Plan\Event\PurchaseRemoved;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Supply\Dto\PurchaseInput;
use App\Supply\Entity\SupplyPurchase;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Exception\SupplyException;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Запис факту закупівлі: у кого купили за заявкою і за скільки.
 *
 * Викликається з бота (кнопка «Оплачено» питає постачальника й суму) і з CRM.
 * Коли закупівля йде впритул зі зміною статусу (notify: false), окремого
 * сповіщення не шлемо: її заявнику вже показує картка статусу — інакше на
 * одну дію прилітає два повідомлення. Подія в журнал пишеться завжди.
 */
class RecordPurchase
{
    public function __construct(
        private EntityManagerInterface $em,
        private PlanEventRecorder $events,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $notify чи сповіщати заявника окремо. false передають лише
     *                     тоді, коли одразу за закупівлею йде зміна статусу:
     *                     її повідомлення вже містить картку з постачальником
     *                     і сумою, і два листи на одну дію ні до чого
     */
    public function __invoke(
        SupplyRequest $request,
        TelegramUser $by,
        PurchaseInput $input,
        bool $notify = true,
    ): SupplyPurchase {
        $this->assertManager($by);

        $purchase = (new SupplyPurchase())->setCreatedBy($by);
        $request->addPurchase($purchase);

        $this->fill($purchase, $input);

        $this->em->persist($purchase);
        $this->save($purchase, $by, updated: false, notify: $notify);

        $this->logger->info('supply: записано закупівлю', [
            'number' => $request->getNumber(),
            'supplier' => $input->supplier->getName(),
            'total' => $purchase->getTotalAmount(),
            'by' => $by->displayName(),
        ]);

        return $purchase;
    }

    public function update(SupplyPurchase $purchase, TelegramUser $by, PurchaseInput $input): SupplyPurchase
    {
        $this->assertManager($by);
        $this->fill($purchase, $input);

        $this->save($purchase, $by, updated: true, notify: true);

        return $purchase;
    }

    public function remove(SupplyPurchase $purchase, TelegramUser $by): void
    {
        $this->assertManager($by);

        $request = $purchase->getRequest();

        // Без закупівлі заявка не має права стояти в «оплачено» й далі —
        // прибирати останній запис уже закритої заявки не даємо.
        if ($request->getStatus()->requiresPurchase() && $request->getPurchases()->count() <= 1) {
            throw new SupplyException(sprintf(
                'Заявка в статусі «%s» не може лишитись без закупівлі. Спершу змініть статус.',
                $request->getStatus()->label(),
            ));
        }

        // Запам'ятовуємо до видалення, сповіщаємо після: інакше картка в
        // повідомленні показувала б скасовану закупівлю.
        $supplier = $purchase->getSupplier()->getName();
        $total = $purchase->getTotalLabel();
        $purchaseId = $purchase->getId();

        $request->removePurchase($purchase);
        $this->em->remove($purchase);

        $this->events->transactional(function () use ($request, $by, $supplier, $total, $purchaseId): void {
            $this->em->flush();
            $this->events->record(new PurchaseRemoved(
                SubjectRef::supply((int) $request->getId()),
                $by->getId(),
                supplier: $supplier,
                total: $total,
                refId: $purchaseId,
            ));
        });

        $this->logger->info('supply: видалено закупівлю', [
            'number' => $request->getNumber(),
            'by' => $by->displayName(),
        ]);
    }

    private function save(SupplyPurchase $purchase, TelegramUser $by, bool $updated, bool $notify): void
    {
        $this->events->transactional(function () use ($purchase, $by, $updated, $notify): void {
            $this->em->flush();
            $this->events->record(new PurchaseRecorded(
                SubjectRef::supply((int) $purchase->getRequest()->getId()),
                $by->getId(),
                refId: (int) $purchase->getId(),
                supplier: $purchase->getSupplier()->getName(),
                total: $purchase->getTotalLabel(),
                updated: $updated,
                notify: $notify,
            ));
        });
    }

    private function fill(SupplyPurchase $purchase, PurchaseInput $input): void
    {
        $quantity = $this->positiveOrNull($input->quantity, 'Кількість');
        $price = $this->positiveOrNull($input->pricePerUnit, 'Ціна');
        $total = $this->positiveOrNull($input->totalAmount, 'Сума');

        // Достатньо будь-яких двох із трьох — третє дораховуємо самі, щоб
        // менеджер не рахував на калькуляторі.
        if ($total === null && $price !== null && $quantity !== null) {
            $total = $this->round((float) $price * (float) $quantity);
        }

        if ($total === null) {
            throw new SupplyException('Вкажіть суму закупівлі або ціну за одиницю разом із кількістю.');
        }

        if ($price === null && $quantity !== null && (float) $quantity > 0) {
            $price = $this->round((float) $total / (float) $quantity);
        }

        $purchase
            ->setSupplier($input->supplier)
            ->setQuantity($quantity)
            ->setPricePerUnit($price)
            ->setTotalAmount($total)
            ->setPayment($input->payment)
            ->setVatIncluded($input->vatIncluded)
            ->setInvoiceNumber($input->invoiceNumber)
            ->setPurchasedAt($input->purchasedAt ?? new DateTime('today', new DateTimeZone('Europe/Kyiv')));
    }

    /** Порожнє поле — це «не вказано», а не нуль. */
    private function positiveOrNull(?string $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace([' ', ','], ['', '.'], trim($value));

        if ($value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value <= 0) {
            throw new SupplyException(sprintf('%s має бути числом більшим за нуль.', $label));
        }

        return $value;
    }

    private function round(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function assertManager(TelegramUser $by): void
    {
        if (! $by->getSupplyRole()->canManage()) {
            throw new SupplyException('Записувати закупівлю може лише менеджер із постачання.');
        }
    }
}
