<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Dto\PurchaseInput;
use App\Supply\Entity\Supplier;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\PaymentType;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Exception\SupplyException;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\RecordPurchase;
use App\Supply\Service\RequestFormatter;
use App\Supply\Service\SupplierDirectory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Закупівля закриває заявку конкретною покупкою.
 *
 * Головна вимога клієнта: заявка не може опинитись в «Оплачено» й далі,
 * поки не відомо, у кого саме її купили.
 */
class PurchaseFlowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecordPurchase $recordPurchase;
    private ChangeStatus $changeStatus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->recordPurchase = self::getContainer()->get(RecordPurchase::class);
        $this->changeStatus = self::getContainer()->get(ChangeStatus::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testStatusCannotMoveToPaidWithoutPurchase(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        ($this->changeStatus)($request, SupplyStatus::InProgress, $manager);

        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('у кого купили');

        ($this->changeStatus)($request, SupplyStatus::Paid, $manager);
    }

    /** Дрібницю часто беруть за готівку й везуть одразу на склад, минаючи «Оплачено». */
    public function testShortcutToStockAlsoNeedsPurchase(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        ($this->changeStatus)($request, SupplyStatus::InProgress, $manager);

        $this->expectException(SupplyException::class);

        ($this->changeStatus)($request, SupplyStatus::InStock, $manager);
    }

    public function testRejectionNeedsNoPurchase(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        ($this->changeStatus)($request, SupplyStatus::Rejected, $manager, 'Немає в бюджеті');

        self::assertSame(SupplyStatus::Rejected, $request->getStatus());
    }

    public function testPurchaseOpensTheWayToPaid(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);
        $supplier = $this->supplier();

        ($this->changeStatus)($request, SupplyStatus::InProgress, $manager);

        ($this->recordPurchase)($request, $manager, new PurchaseInput(
            supplier: $supplier,
            totalAmount: '12500.50',
            invoiceNumber: 'РН-114',
            payment: PaymentType::Cash,
        ));

        ($this->changeStatus)($request, SupplyStatus::Paid, $manager);

        self::assertSame(SupplyStatus::Paid, $request->getStatus());
        self::assertTrue($request->isPurchased());
        self::assertSame('12500.50', $request->getPurchaseTotal());
    }

    public function testTotalIsCalculatedFromPriceAndQuantity(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        $purchase = ($this->recordPurchase)($request, $manager, new PurchaseInput(
            supplier: $this->supplier(),
            quantity: '2.5',
            pricePerUnit: '4000',
        ));

        self::assertSame('10000.00', $purchase->getTotalAmount());
    }

    public function testPricePerUnitIsCalculatedFromTotal(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        $purchase = ($this->recordPurchase)($request, $manager, new PurchaseInput(
            supplier: $this->supplier(),
            totalAmount: '10000',
            quantity: '2.5',
        ));

        self::assertSame('4000.00', $purchase->getPricePerUnit());
    }

    public function testAmountWrittenWithCommaAndSpacesIsAccepted(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        $purchase = ($this->recordPurchase)($request, $manager, new PurchaseInput(
            supplier: $this->supplier(),
            totalAmount: '12 500,50',
        ));

        self::assertSame('12500.50', $purchase->getTotalAmount());
        self::assertSame('12 500,50 ₴', $purchase->getTotalLabel());
    }

    public function testPurchaseWithoutAmountIsRejected(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('Вкажіть суму');

        ($this->recordPurchase)($request, $manager, new PurchaseInput(supplier: $this->supplier()));
    }

    public function testWorkerCannotRecordPurchase(): void
    {
        [$worker] = $this->users();
        $request = $this->request($worker);

        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('лише менеджер');

        ($this->recordPurchase)($request, $worker, new PurchaseInput(
            supplier: $this->supplier(),
            totalAmount: '100',
        ));
    }

    public function testSeveralSuppliersSumUp(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        ($this->recordPurchase)($request, $manager, new PurchaseInput(
            supplier: $this->supplier('ФОП Петренко'),
            totalAmount: '10000.10',
        ));
        ($this->recordPurchase)($request, $manager, new PurchaseInput(
            supplier: $this->supplier('ТОВ Доставка'),
            totalAmount: '2500.20',
        ));

        self::assertCount(2, $request->getPurchases());
        self::assertSame('12500.30', $request->getPurchaseTotal(), 'копійки не пливуть');
    }

    public function testLastPurchaseCannotBeRemovedFromPaidRequest(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        ($this->changeStatus)($request, SupplyStatus::InProgress, $manager);
        $purchase = ($this->recordPurchase)($request, $manager, new PurchaseInput(
            supplier: $this->supplier(),
            totalAmount: '500',
        ));
        ($this->changeStatus)($request, SupplyStatus::Paid, $manager);

        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('не може лишитись без закупівлі');

        $this->recordPurchase->remove($purchase, $manager);
    }

    /** Заявник теж бачить, у кого й за скільки купили — так вирішив клієнт. */
    public function testCardShowsSupplierAndAmountToEveryone(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        ($this->recordPurchase)($request, $manager, new PurchaseInput(
            supplier: $this->supplier('ФОП Петренко О.П.'),
            totalAmount: '12500.50',
            invoiceNumber: 'РН-114',
        ));

        $card = self::getContainer()->get(RequestFormatter::class)->card($request, forManager: false);

        self::assertStringContainsString('ФОП Петренко О.П.', $card);
        self::assertStringContainsString('12 500,50 ₴', $card);
        self::assertStringContainsString('РН-114', $card);
    }

    private function supplier(string $name = 'ФОП Петренко О.П.'): Supplier
    {
        return self::getContainer()->get(SupplierDirectory::class)->findOrCreate($name . ' ' . uniqid());
    }

    private function request(TelegramUser $author): SupplyRequest
    {
        return (self::getContainer()->get(CreateRequest::class))(
            $author,
            new CreateRequestInput(item: 'Пісок річковий', quantity: '5', unit: Unit::CubicMeter),
        );
    }

    /** @return TelegramUser[] */
    private function users(): array
    {
        $worker = (new TelegramUser())
            ->setTelegramId('purchase-worker-' . uniqid())
            ->setFirstName('Робітник')
            ->setSupplyRole(SupplyRole::Worker);

        $manager = (new TelegramUser())
            ->setTelegramId('purchase-manager-' . uniqid())
            ->setFirstName('Менеджер')
            ->setSupplyRole(SupplyRole::Manager);

        $this->em->persist($worker);
        $this->em->persist($manager);
        $this->em->flush();

        return [$worker, $manager];
    }
}
