<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Dto\PurchaseInput;
use App\Supply\Entity\Department;
use App\Supply\Entity\Supplier;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\RecordPurchase;
use App\Supply\Service\SupplierDirectory;
use App\Supply\Service\SupplyReports;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Звіти рахують гроші, тож перевіряємо саме цифри, а не «щось повернулось».
 *
 * База тестова спільна, тому дані інших тестів теж потрапляють у підсумки —
 * звідси перевірки на конкретних постачальниках і підрозділах, а не на
 * загальних сумах.
 */
class ReportsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SupplyReports $reports;
    private RecordPurchase $recordPurchase;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->reports = self::getContainer()->get(SupplyReports::class);
        $this->recordPurchase = self::getContainer()->get(RecordPurchase::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testSupplierTurnoverAndShare(): void
    {
        [$worker, $manager] = $this->users();
        $big = $this->supplier('ТОВ Великий');
        $small = $this->supplier('ФОП Малий');

        ($this->recordPurchase)($this->request($worker), $manager, new PurchaseInput($big, totalAmount: '7500.50'));
        ($this->recordPurchase)($this->request($worker), $manager, new PurchaseInput($big, totalAmount: '2500'));
        ($this->recordPurchase)($this->request($worker), $manager, new PurchaseInput($small, totalAmount: '1000'));

        $rows = $this->indexById($this->today()['suppliers']);

        self::assertSame(2, $rows[$big->getId()]['purchases']);
        self::assertSame(10000.5, $rows[$big->getId()]['total'], 'копійки не губляться');
        self::assertSame(1000.0, $rows[$small->getId()]['total']);
        // Частки рахуються від усіх закупівель у базі, тож перевіряємо лише
        // співвідношення: великий постачальник має більшу частку.
        self::assertGreaterThan($rows[$small->getId()]['share'], $rows[$big->getId()]['share']);
    }

    public function testDepartmentSpending(): void
    {
        [$worker, $manager] = $this->users();
        $department = (new Department())->setName('Цех №7 ' . uniqid());
        $this->em->persist($department);
        $worker->setDepartment($department);
        $this->em->flush();

        ($this->recordPurchase)($this->request($worker), $manager, new PurchaseInput($this->supplier(), totalAmount: '3000'));
        ($this->recordPurchase)($this->request($worker), $manager, new PurchaseInput($this->supplier(), totalAmount: '2000'));

        $rows = $this->indexById($this->today()['departments']);

        self::assertSame(2, $rows[$department->getId()]['requests']);
        self::assertSame(5000.0, $rows[$department->getId()]['total']);
    }

    public function testItemsAreGroupedByName(): void
    {
        [$worker, $manager] = $this->users();
        $item = 'Цемент М400 ' . uniqid();

        ($this->recordPurchase)($this->request($worker, $item), $manager, new PurchaseInput($this->supplier(), totalAmount: '1500'));
        ($this->recordPurchase)($this->request($worker, $item), $manager, new PurchaseInput($this->supplier(), totalAmount: '2500'));

        $rows = [];
        foreach ($this->today()['items'] as $row) {
            $rows[$row['item']] = $row;
        }

        self::assertSame(2, $rows[$item]['requests']);
        self::assertSame(4000.0, $rows[$item]['total']);
    }

    /** Закупівлі поза періодом у звіт не потрапляють. */
    public function testPurchasesOutsideThePeriodAreExcluded(): void
    {
        [$worker, $manager] = $this->users();
        $supplier = $this->supplier('ТОВ Торішній');

        ($this->recordPurchase)($this->request($worker), $manager, new PurchaseInput(
            $supplier,
            totalAmount: '9999',
            purchasedAt: new DateTime('-2 years'),
        ));

        $rows = $this->indexById($this->today()['suppliers']);

        self::assertArrayNotHasKey($supplier->getId(), $rows);
    }

    /** Середній строк рахується лише по заявках, що доїхали до складу. */
    public function testLeadTimeIsNullWhenNothingClosed(): void
    {
        $report = $this->reports->build(new DateTime('+5 years'), new DateTime('+5 years +1 day'));

        self::assertNull($report['totals']['leadTimeDays']);
        self::assertSame(0, $report['totals']['requests']);
        self::assertSame(0.0, $report['totals']['spent']);
    }

    public function testClosedAndOverdueAreCounted(): void
    {
        [$worker, $manager] = $this->users();
        $changeStatus = self::getContainer()->get(ChangeStatus::class);

        $closed = $this->request($worker);
        $changeStatus($closed, SupplyStatus::InProgress, $manager);
        ($this->recordPurchase)($closed, $manager, new PurchaseInput($this->supplier(), totalAmount: '100'), notify: false);
        // Гроші йдуть через директора, тож і в звітах шлях той самий:
        // директор підтверджує, а везе й закриває вже менеджер.
        $changeStatus($closed, SupplyStatus::Approval, $manager);
        $changeStatus($closed, SupplyStatus::Approved, $this->director());
        $changeStatus($closed, SupplyStatus::Paid, $manager);
        $changeStatus($closed, SupplyStatus::InStock, $manager);

        $totals = $this->today()['totals'];

        self::assertGreaterThanOrEqual(1, $totals['closed']);
        self::assertNotNull($totals['leadTimeDays'], 'є закрита заявка — має бути й середній строк');
    }

    private function today(): array
    {
        $today = new DateTime('today', new DateTimeZone('Europe/Kyiv'));

        return $this->reports->build($today, $today);
    }

    private function indexById(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[$row['id']] = $row;
        }

        return $indexed;
    }

    private function supplier(string $name = 'ФОП Петренко'): Supplier
    {
        return self::getContainer()->get(SupplierDirectory::class)->findOrCreate($name . ' ' . uniqid());
    }

    private function request(TelegramUser $author, string $item = 'Пісок річковий'): SupplyRequest
    {
        return (self::getContainer()->get(CreateRequest::class))(
            $author,
            new CreateRequestInput(item: $item, quantity: '5', unit: Unit::CubicMeter),
        );
    }

    private function director(): TelegramUser
    {
        $director = (new TelegramUser())
            ->setTelegramId('report-director-' . uniqid())
            ->setFirstName('Тест-Звіт')
            ->setSupplyRole(SupplyRole::Director);

        $this->em->persist($director);
        $this->em->flush();

        return $director;
    }

    /** @return TelegramUser[] */
    private function users(): array
    {
        $worker = (new TelegramUser())
            ->setTelegramId('report-worker-' . uniqid())
            ->setFirstName('Тест-Звіт')
            ->setSupplyRole(SupplyRole::Worker);

        $manager = (new TelegramUser())
            ->setTelegramId('report-manager-' . uniqid())
            ->setFirstName('Тест-Звіт')
            ->setSupplyRole(SupplyRole::Manager);

        $this->em->persist($worker);
        $this->em->persist($manager);
        $this->em->flush();

        return [$worker, $manager];
    }
}
