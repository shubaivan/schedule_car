<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Dto\PurchaseInput;
use App\Supply\Entity\Supplier;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Service\AddComment;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\RecordPurchase;
use App\Supply\Service\SupplierDirectory;
use App\Supply\Service\SupplyNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Жорстка вимога клієнта: заявник має знати про КОЖНУ зміну своєї заявки.
 *
 * Тест підміняє SupplyNotifier шпигуном і перевіряє, що жодна операція не
 * проходить повз нього. Саме тут ловиться найтиповіша регресія — новий сервіс
 * навчився міняти заявку й забув сповістити.
 */
class NotificationCoverageTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private object $notifier;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->notifier = new class extends SupplyNotifier {
            /** @var string[] */
            public array $calls = [];

            public function __construct()
            {
            }

            public function requestCreated(SupplyRequest $request): void
            {
                $this->calls[] = 'requestCreated';
            }

            public function statusChanged($request, $log): void
            {
                $this->calls[] = 'statusChanged';
            }

            public function commentAdded($comment): void
            {
                $this->calls[] = 'commentAdded';
            }

            public function purchaseRecorded($purchase, bool $updated = false): void
            {
                $this->calls[] = $updated ? 'purchaseUpdated' : 'purchaseRecorded';
            }

            public function purchaseRemoved($request, string $supplier, string $total): void
            {
                $this->calls[] = 'purchaseRemoved';
            }
        };

        $container->set(SupplyNotifier::class, $this->notifier);

        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testCreationNotifies(): void
    {
        $this->request($this->users()[0]);

        self::assertSame(['requestCreated'], $this->notifier->calls);
    }

    public function testStatusChangeNotifies(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);
        $this->notifier->calls = [];

        (self::getContainer()->get(ChangeStatus::class))($request, SupplyStatus::InProgress, $manager);

        self::assertSame(['statusChanged'], $this->notifier->calls);
    }

    public function testCommentNotifies(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);
        $this->notifier->calls = [];

        (self::getContainer()->get(AddComment::class))($request, $manager, 'Шукаю постачальника');

        self::assertSame(['commentAdded'], $this->notifier->calls);
    }

    /** Кнопка «🧾 Закупівля» без зміни статусу — заявник усе одно має знати. */
    public function testStandalonePurchaseNotifies(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);
        $this->notifier->calls = [];

        (self::getContainer()->get(RecordPurchase::class))($request, $manager, $this->purchase());

        self::assertSame(['purchaseRecorded'], $this->notifier->calls);
    }

    /**
     * А коли закупівля йде впритул зі зміною статусу — рівно одне повідомлення:
     * у картці статусу вже видно постачальника й суму.
     */
    public function testPurchaseFollowedByStatusSendsSingleMessage(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);
        $changeStatus = self::getContainer()->get(ChangeStatus::class);
        $changeStatus($request, SupplyStatus::InProgress, $manager);
        $this->notifier->calls = [];

        (self::getContainer()->get(RecordPurchase::class))($request, $manager, $this->purchase(), notify: false);
        $changeStatus($request, SupplyStatus::Paid, $manager);

        self::assertSame(['statusChanged'], $this->notifier->calls);
    }

    public function testPurchaseEditAndRemovalNotify(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);
        $recordPurchase = self::getContainer()->get(RecordPurchase::class);
        $purchase = $recordPurchase($request, $manager, $this->purchase());
        $this->notifier->calls = [];

        $recordPurchase->update($purchase, $manager, $this->purchase('9000'));
        $recordPurchase->remove($purchase, $manager);

        self::assertSame(['purchaseUpdated', 'purchaseRemoved'], $this->notifier->calls);
    }

    private function purchase(string $amount = '12500'): PurchaseInput
    {
        return new PurchaseInput(supplier: $this->supplier(), totalAmount: $amount);
    }

    private function supplier(): Supplier
    {
        return self::getContainer()->get(SupplierDirectory::class)
            ->findOrCreate('ФОП Петренко ' . uniqid());
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
            ->setTelegramId('notify-worker-' . uniqid())
            ->setFirstName('Робітник')
            ->setSupplyRole(SupplyRole::Worker);

        $manager = (new TelegramUser())
            ->setTelegramId('notify-manager-' . uniqid())
            ->setFirstName('Менеджер')
            ->setSupplyRole(SupplyRole::Manager);

        $this->em->persist($worker);
        $this->em->persist($manager);
        $this->em->flush();

        return [$worker, $manager];
    }
}
