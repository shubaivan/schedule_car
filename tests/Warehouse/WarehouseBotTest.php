<?php

namespace App\Tests\Warehouse;

use App\EventSubscriber\WarehouseGate;
use App\Supply\Enum\SupplyRole;
use App\Telegram\Start\Command\StartCommand;
use App\Warehouse\Entity\WhDocument;
use App\Warehouse\Enum\MovementType;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Service\DocumentStore;
use App\Warehouse\Service\RecordMovement;
use App\Warehouse\Service\WarehouseCards;
use App\Warehouse\Service\WarehouseSection;
use App\Warehouse\Telegram\WarehouseCallback;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** Що бачить людина в боті після скану, і де розділ узагалі видно. */
class WarehouseBotTest extends KernelTestCase
{
    use WarehouseFixtures;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
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

    /** Відсканували щит на об'єкті — видно, чий він, де, з якого дня й скільки діб. */
    public function testScanCardTellsWhereAndSince(): void
    {
        $manager = $this->person();
        $warehouse = $this->warehouse();
        $site = $this->site($this->client('ТОВ Будінвест-Тест'), 'ЖК Сонячний-Тест');
        $item = $this->item(rate: '25.00');
        $item->setAttributes(['Розміри' => '1200×600']);
        $item->setPurchasePrice('4800.00');

        $record = self::getContainer()->get(RecordMovement::class);
        $record(MovementType::Receipt, new DateTime('2026-09-01'), null, $warehouse, [['item' => $item, 'quantity' => 1]], $manager);
        $record(MovementType::Shipment, new DateTime('2026-09-10'), $warehouse, $site, [['item' => $item, 'quantity' => 1]], $manager);

        $cards = self::getContainer()->get(WarehouseCards::class);
        $forWorker = $cards->item($item, false, new DateTime('2026-09-26'));

        self::assertStringContainsString('ЖК Сонячний-Тест (ТОВ Будінвест-Тест)', $forWorker);
        self::assertStringContainsString('з 10.09.2026 (16 діб)', $forWorker);
        self::assertStringContainsString('Розміри: 1200×600', $forWorker);
        self::assertStringNotContainsString('4 800', $forWorker, 'робітнику без цін');

        $forManager = $cards->item($item, true, new DateTime('2026-09-26'));
        self::assertStringContainsString('ціна 4 800,00 грн', $forManager);
        self::assertStringContainsString('оренда 25,00 грн/доба', $forManager);
    }

    public function testBulkCardListsPlaces(): void
    {
        $manager = $this->person();
        $warehouse = $this->warehouse('Склад-Тест');
        $site = $this->site(null, 'Власний ЖК-Тест');
        $locks = $this->item(Tracking::Bulk, 'Замок клиновий');

        $record = self::getContainer()->get(RecordMovement::class);
        $record(MovementType::Receipt, new DateTime(), null, $warehouse, [['item' => $locks, 'quantity' => 300]], $manager);
        $record(MovementType::Transfer, new DateTime(), $warehouse, $site, [['item' => $locks, 'quantity' => 100]], $manager);

        $card = self::getContainer()->get(WarehouseCards::class)->item($locks, false);

        self::assertStringContainsString('Склад-Тест — 200 шт', $card);
        self::assertStringContainsString('Власний ЖК-Тест (власний) — 100 шт', $card);
        self::assertStringContainsString('Разом: 300 шт', $card);
    }

    public function testMenuButtonOnlyForThoseWhoRunTheWarehouse(): void
    {
        $section = new WarehouseSection(true);

        self::assertTrue($section->inMenuFor($this->person(SupplyRole::Manager)));
        self::assertTrue($section->inMenuFor($this->person(SupplyRole::Director)));
        self::assertFalse($section->inMenuFor($this->person(SupplyRole::Worker)));
        self::assertFalse((new WarehouseSection(false))->inMenuFor($this->person(SupplyRole::Admin)));

        $callbacks = [];

        foreach (StartCommand::mainMenuKeyboard(true, true)->inline_keyboard as $row) {
            foreach ($row as $button) {
                $callbacks[] = $button->callback_data;
            }
        }

        self::assertContains(WarehouseCallback::MENU, $callbacks);
    }

    /** Вимкнений склад не існує: /sklad — 404, а не редирект на вхід. */
    public function testSwitchedOffWarehouseIs404(): void
    {
        $gate = new WarehouseGate(new WarehouseSection(false));
        $event = new RequestEvent(self::$kernel, Request::create('/sklad/items/1'), HttpKernelInterface::MAIN_REQUEST);

        $this->expectException(NotFoundHttpException::class);
        $gate->onRequest($event);
    }

    /** Той самий крон везе на Диск і документи складу — і так само чесно каже, що Диска немає. */
    public function testDriveSyncCountsWarehouseDocuments(): void
    {
        $this->em->createQuery('DELETE FROM ' . WhDocument::class)->execute();
        $this->em->createQuery('DELETE FROM App\Supply\Entity\SupplyAttachment')->execute();

        self::getContainer()->get(DocumentStore::class)->attach(
            $this->client(),
            $this->person(),
            '%PDF договір',
            'Договір оренди.pdf',
            'application/pdf',
        );

        $command = new CommandTester((new Application(self::$kernel))->find('supply:drive-sync'));
        $command->execute([]);

        self::assertSame(0, $command->getStatusCode());
        self::assertStringContainsString('без копії: 1', $command->getDisplay());
    }
}
