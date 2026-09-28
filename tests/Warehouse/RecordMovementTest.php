<?php

namespace App\Tests\Warehouse;

use App\Supply\Enum\SupplyRole;
use App\Warehouse\Enum\ItemState;
use App\Warehouse\Enum\MovementType;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Exception\WarehouseException;
use App\Warehouse\Service\RecordMovement;
use App\Warehouse\Service\WarehouseStock;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Рухи мусять сходитись із фактом: щит не «поїде» зі складу, де його немає,
 * і замків не повернуть більше, ніж відвантажили. Інакше картка позиції й
 * залишки об'єкта розійдуться з історією.
 */
class RecordMovementTest extends KernelTestCase
{
    use WarehouseFixtures;

    private EntityManagerInterface $em;
    private RecordMovement $record;
    private WarehouseStock $stock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->record = self::getContainer()->get(RecordMovement::class);
        $this->stock = self::getContainer()->get(WarehouseStock::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    protected function em(): EntityManagerInterface
    {
        return $this->em;
    }

    public function testUnitItemTravelsWarehouseSiteAndBack(): void
    {
        $manager = $this->person();
        $warehouse = $this->warehouse();
        $client = $this->client();
        $site = $this->site($client);
        $item = $this->item(rate: '25.00');

        ($this->record)(MovementType::Receipt, new DateTime('2026-09-01'), null, $warehouse, [['item' => $item, 'quantity' => 1]], $manager);
        self::assertSame($warehouse->getId(), $item->getCurrentSite()?->getId());

        $shipment = ($this->record)(MovementType::Shipment, new DateTime('2026-09-10'), $warehouse, $site, [['item' => $item, 'quantity' => 1]], $manager, '15');
        self::assertSame($site->getId(), $item->getCurrentSite()?->getId());
        self::assertSame('2026-09-10', $item->getCurrentSince()?->format('Y-m-d'));
        self::assertSame($client->getId(), $shipment->getClient()?->getId());
        self::assertSame('25.00', $shipment->getLines()->first()->getRentalRate(), 'ставка з картки, коли не вказали');

        ($this->record)(MovementType::Return, new DateTime('2026-09-20'), $site, $warehouse, [['item' => $item, 'quantity' => 1]], $manager);
        self::assertSame($warehouse->getId(), $item->getCurrentSite()?->getId());
    }

    public function testUnitItemCannotLeaveWhereItIsNot(): void
    {
        $manager = $this->person();
        $warehouse = $this->warehouse();
        $site = $this->site($this->client());
        $item = $this->item();

        $this->expectException(WarehouseException::class);
        $this->expectExceptionMessage('не там');

        ($this->record)(MovementType::Shipment, new DateTime(), $warehouse, $site, [['item' => $item, 'quantity' => 1]], $manager);
    }

    public function testUnitItemIsReceivedOnce(): void
    {
        $manager = $this->person();
        $warehouse = $this->warehouse();
        $item = $this->item();

        ($this->record)(MovementType::Receipt, new DateTime(), null, $warehouse, [['item' => $item, 'quantity' => 1]], $manager);

        $this->expectException(WarehouseException::class);
        ($this->record)(MovementType::Receipt, new DateTime(), null, $warehouse, [['item' => $item, 'quantity' => 1]], $manager);
    }

    public function testBulkBalancesPerPlace(): void
    {
        $manager = $this->person();
        $warehouse = $this->warehouse();
        $site = $this->site($this->client());
        $locks = $this->item(Tracking::Bulk, 'Замок клиновий');

        ($this->record)(MovementType::Receipt, new DateTime(), null, $warehouse, [['item' => $locks, 'quantity' => 500]], $manager);
        ($this->record)(MovementType::Shipment, new DateTime(), $warehouse, $site, [['item' => $locks, 'quantity' => 200]], $manager);
        ($this->record)(MovementType::Return, new DateTime(), $site, $warehouse, [['item' => $locks, 'quantity' => 50]], $manager);

        self::assertSame(350, $this->stock->balanceAt($locks, $warehouse));
        self::assertSame(150, $this->stock->balanceAt($locks, $site));
        self::assertSame(500, $this->stock->total($locks));
        self::assertCount(1, $this->stock->contentsOf($site));
    }

    public function testCannotReturnMoreThanWasShipped(): void
    {
        $manager = $this->person();
        $warehouse = $this->warehouse();
        $site = $this->site($this->client());
        $locks = $this->item(Tracking::Bulk, 'Замок клиновий');

        ($this->record)(MovementType::Receipt, new DateTime(), null, $warehouse, [['item' => $locks, 'quantity' => 100]], $manager);
        ($this->record)(MovementType::Shipment, new DateTime(), $warehouse, $site, [['item' => $locks, 'quantity' => 40]], $manager);

        $this->expectException(WarehouseException::class);
        $this->expectExceptionMessage('є 40');

        ($this->record)(MovementType::Return, new DateTime(), $site, $warehouse, [['item' => $locks, 'quantity' => 41]], $manager);
    }

    public function testShipmentGoesFromWarehouseToSiteOnly(): void
    {
        $manager = $this->person();
        $warehouse = $this->warehouse();
        $otherWarehouse = $this->warehouse('Другий склад');
        $item = $this->item();

        ($this->record)(MovementType::Receipt, new DateTime(), null, $warehouse, [['item' => $item, 'quantity' => 1]], $manager);

        $this->expectException(WarehouseException::class);
        ($this->record)(MovementType::Shipment, new DateTime(), $warehouse, $otherWarehouse, [['item' => $item, 'quantity' => 1]], $manager);
    }

    public function testWriteOffRemovesUnitItemForGood(): void
    {
        $manager = $this->person();
        $warehouse = $this->warehouse();
        $item = $this->item();

        ($this->record)(MovementType::Receipt, new DateTime(), null, $warehouse, [['item' => $item, 'quantity' => 1]], $manager);
        ($this->record)(MovementType::WriteOff, new DateTime(), $warehouse, null, [['item' => $item, 'quantity' => 1]], $manager);

        self::assertSame(ItemState::WrittenOff, $item->getState());
        self::assertNull($item->getCurrentSite());

        $this->expectException(WarehouseException::class);
        ($this->record)(MovementType::Receipt, new DateTime(), null, $warehouse, [['item' => $item, 'quantity' => 1]], $manager);
    }

    public function testWorkerCannotRecordMovements(): void
    {
        $this->expectException(WarehouseException::class);

        ($this->record)(MovementType::Receipt, new DateTime(), null, $this->warehouse(), [['item' => $this->item(), 'quantity' => 1]], $this->person(SupplyRole::Worker));
    }
}
