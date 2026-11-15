<?php

namespace App\Tests\Warehouse;

use App\Warehouse\Enum\MovementType;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Exception\WarehouseException;
use App\Warehouse\Service\RecordMovement;
use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Дві паралельні відвантаження тієї самої позиції: рух бере рядок wh_item під
 * FOR UPDATE ще ДО перевірки залишку, тож другий чекає коміту першого й бачить уже
 * новий залишок — у мінус не піде. Тут «інший запит» — окреме з'єднання, що тримає
 * блокування: наш рух має на ньому чекати (lock_timeout), а не рахувати залишок поруч.
 *
 * Дані комітяться (друге з'єднання бачить лише закомічене) і прибираються в tearDown.
 */
final class StockLockTest extends KernelTestCase
{
    use WarehouseFixtures;

    private EntityManagerInterface $em;

    /** @var array<string, list<int>> */
    private array $created = ['user' => [], 'site' => [], 'item' => [], 'category' => [], 'client' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement('SET lock_timeout = 0');
        $items = $this->created['item'] ?: [0];
        $movements = array_map('intval', $db->fetchFirstColumn('SELECT DISTINCT movement_id FROM wh_movement_line WHERE item_id IN (?)', [$items], [ArrayParameterType::INTEGER])) ?: [0];
        $in = static fn (array $ids) => implode(',', array_map('intval', $ids ?: [0]));

        $db->executeStatement("DELETE FROM plan_event WHERE (subject_type = 'wh_movement' AND subject_id IN ({$in($movements)})) OR (subject_type = 'wh_item' AND subject_id IN ({$in($items)}))");
        $db->executeStatement("DELETE FROM plan_subject_activity WHERE (subject_type = 'wh_movement' AND subject_id IN ({$in($movements)})) OR (subject_type = 'wh_item' AND subject_id IN ({$in($items)}))");
        $db->executeStatement("DELETE FROM search_document WHERE (subject_type = 'wh_movement' AND subject_id IN ({$in($movements)})) OR (subject_type = 'wh_item' AND subject_id IN ({$in($items)}))");
        $db->executeStatement("DELETE FROM wh_activity WHERE (subject_type = 'movement' AND subject_id IN ({$in($movements)})) OR (subject_type = 'item' AND subject_id IN ({$in($items)}))");
        $db->executeStatement("DELETE FROM wh_movement_line WHERE movement_id IN ({$in($movements)})");
        $db->executeStatement("DELETE FROM wh_movement WHERE id IN ({$in($movements)})");
        $db->executeStatement("DELETE FROM wh_item WHERE id IN ({$in($items)})");
        $db->executeStatement("DELETE FROM wh_category WHERE id IN ({$in($this->created['category'])})");
        $db->executeStatement("DELETE FROM wh_site WHERE id IN ({$in($this->created['site'])})");
        $db->executeStatement("DELETE FROM wh_client WHERE id IN ({$in($this->created['client'])})");
        $db->executeStatement("DELETE FROM telegram_user WHERE id IN ({$in($this->created['user'])})");

        parent::tearDown();
    }

    protected function em(): EntityManagerInterface
    {
        return $this->em;
    }

    public function testShipmentWaitsForTheItemLockAndNeverGoesNegative(): void
    {
        $record = self::getContainer()->get(RecordMovement::class);
        $manager = $this->person();
        $warehouse = $this->warehouse();
        $client = $this->client();
        $site = $this->site($client);
        $item = $this->item(Tracking::Bulk, 'Замок клиновий');
        $this->created = [
            'user' => [(int) $manager->getId()],
            'site' => [(int) $warehouse->getId(), (int) $site->getId()],
            'item' => [(int) $item->getId()],
            'category' => [(int) $item->getCategory()->getId()],
            'client' => [(int) $client->getId()],
        ];

        $record(MovementType::Receipt, new DateTime('2026-09-01'), null, $warehouse, [['item' => $item, 'quantity' => 10]], $manager);

        // «Інший запит» записує свою відвантаження й тримає рядок позиції.
        $other = $this->otherConnection();
        $other->beginTransaction();
        $other->executeQuery('SELECT id FROM wh_item WHERE id = ? FOR UPDATE', [$item->getId()]);

        $this->em->getConnection()->executeStatement("SET lock_timeout = '300ms'");

        try {
            $record(MovementType::Shipment, new DateTime('2026-09-02'), $warehouse, $site, [['item' => $item, 'quantity' => 6]], $manager);
            self::fail('рух мав чекати на блокування позиції');
        } catch (Throwable $e) {
            // 55P03 lock_not_available — саме на рядку wh_item, до будь-якої перевірки залишку.
            self::assertStringContainsString('lock timeout', $e->getMessage());
            self::assertStringContainsString('wh_item', $e->getMessage());
        } finally {
            $other->rollBack();
            $other->close();
        }

        $this->em->getConnection()->executeStatement('SET lock_timeout = 0');

        // Після звільнення: 6 з 10 — так, ще 6 — ні (лишилось 4).
        $record(MovementType::Shipment, new DateTime('2026-09-03'), $warehouse, $site, [['item' => $item, 'quantity' => 6]], $manager);
        $this->expectException(WarehouseException::class);
        $record(MovementType::Shipment, new DateTime('2026-09-04'), $warehouse, $site, [['item' => $item, 'quantity' => 6]], $manager);
    }

    private function otherConnection(): Connection
    {
        return DriverManager::getConnection($this->em->getConnection()->getParams());
    }
}
