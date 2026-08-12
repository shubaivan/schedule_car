<?php

namespace App\Supply\Service;

use App\Entity\TelegramUser;
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
 * Окремого сповіщення не шлемо: закупівля йде поруч зі зміною статусу, а її
 * заявнику вже показує SupplyNotifier — інакше на одну дію прилітає два
 * повідомлення.
 */
class RecordPurchase
{
    public function __construct(
        private EntityManagerInterface $em,
        private SupplyNotifier $notifier,
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
        $this->em->flush();

        $this->logger->info('supply: записано закупівлю', [
            'number' => $request->getNumber(),
            'supplier' => $input->supplier->getName(),
            'total' => $purchase->getTotalAmount(),
            'by' => $by->displayName(),
        ]);

        if ($notify) {
            $this->notifier->purchaseRecorded($purchase);
        }

        return $purchase;
    }

    public function update(SupplyPurchase $purchase, TelegramUser $by, PurchaseInput $input): SupplyPurchase
    {
        $this->assertManager($by);
        $this->fill($purchase, $input);

        $this->em->flush();

        $this->notifier->purchaseRecorded($purchase, updated: true);

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

        $request->removePurchase($purchase);
        $this->em->remove($purchase);
        $this->em->flush();

        $this->logger->info('supply: видалено закупівлю', [
            'number' => $request->getNumber(),
            'by' => $by->displayName(),
        ]);

        $this->notifier->purchaseRemoved($request, $supplier, $total);
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
