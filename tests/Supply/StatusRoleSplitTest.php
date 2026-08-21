<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Dto\PurchaseInput;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Exception\SupplyException;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\RecordPurchase;
use App\Supply\Service\RequestPresenter;
use App\Supply\Service\SupplierDirectory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Кнопки директора і кнопки менеджера не перетинаються.
 *
 * Вимога клієнта від 19.08.2026: керівник ухвалює рішення про гроші
 * («Підтверджено / На оплату», «В списку очікування», «Відхилена»), далі заявку
 * веде менеджер («Оплачено», «Доставка», «На складі», «Готова»). Тести стережуть
 * саме межу між наборами: побачити кнопку, яку не можна натиснути, — теж баг.
 */
class StatusRoleSplitTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ChangeStatus $changeStatus;
    private RecordPurchase $recordPurchase;
    private RequestPresenter $presenter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->changeStatus = self::getContainer()->get(ChangeStatus::class);
        $this->recordPurchase = self::getContainer()->get(RecordPurchase::class);
        $this->presenter = self::getContainer()->get(RequestPresenter::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testDirectorSeesOnlyHisOwnButtonsOnApproval(): void
    {
        [$worker, $manager, $director] = $this->users();
        $request = $this->awaitingApproval($worker, $manager);

        self::assertSame(
            ['approved', 'waiting', 'rejected'],
            $this->transitions($request, $director),
        );
        self::assertSame([], $this->transitions($request, $manager), 'менеджеру тут тиснути нічого');
    }

    public function testManagerLeadsTheChainAfterApproval(): void
    {
        [$worker, $manager, $director] = $this->users();
        $request = $this->awaitingApproval($worker, $manager);

        ($this->changeStatus)($request, SupplyStatus::Approved, $director);

        self::assertSame(['paid', 'rejected'], $this->transitions($request, $manager));
        self::assertSame([], $this->transitions($request, $director), 'оплату проводить не директор');
    }

    /** Директор не тисне кнопки менеджера навіть із правильним статусом. */
    public function testDirectorCannotPayInsteadOfManager(): void
    {
        [$worker, $manager, $director] = $this->users();
        $request = $this->awaitingApproval($worker, $manager);
        ($this->changeStatus)($request, SupplyStatus::Approved, $director);

        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('менеджер');

        ($this->changeStatus)($request, SupplyStatus::Paid, $director);
    }

    /** «В список очікування» — пауза, а не кінець: гроші знайшлись, заявка жива. */
    public function testWaitingListReturnsToTheSameDecision(): void
    {
        [$worker, $manager, $director] = $this->users();
        $request = $this->awaitingApproval($worker, $manager);

        ($this->changeStatus)($request, SupplyStatus::Waiting, $director);

        self::assertNull($request->getClosedAt(), 'заявка в очікуванні не закрита');
        self::assertContains(SupplyStatus::Waiting, SupplyStatus::openCases());
        self::assertSame(['approval', 'rejected'], $this->transitions($request, $director));

        ($this->changeStatus)($request, SupplyStatus::Approval, $director);

        self::assertSame(SupplyStatus::Approval, $request->getStatus());
    }

    public function testManagerCannotPullRequestOutOfWaitingList(): void
    {
        [$worker, $manager, $director] = $this->users();
        $request = $this->awaitingApproval($worker, $manager);
        ($this->changeStatus)($request, SupplyStatus::Waiting, $director);

        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('директор');

        ($this->changeStatus)($request, SupplyStatus::Approval, $manager);
    }

    /** «Готова» закриває заявку так само, як «На складі»: матеріал у людей. */
    public function testReadyClosesRequestLikeStock(): void
    {
        [$worker, $manager, $director] = $this->users();
        $request = $this->awaitingApproval($worker, $manager);

        ($this->changeStatus)($request, SupplyStatus::Approved, $director);
        ($this->changeStatus)($request, SupplyStatus::Paid, $manager);
        ($this->changeStatus)($request, SupplyStatus::Ready, $manager);

        self::assertTrue($request->getStatus()->isFinal());
        self::assertNotNull($request->getClosedAt());
        self::assertSame([], $this->transitions($request, $manager));
    }

    /** Гроші повз директора не ходять: «Оплачено» відразу з «В роботі» немає. */
    public function testPaymentStillGoesThroughApproval(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);
        ($this->changeStatus)($request, SupplyStatus::InProgress, $manager);

        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('Не можна перевести заявку');

        ($this->changeStatus)($request, SupplyStatus::Paid, $manager);
    }

    /** @return string[] */
    private function transitions(SupplyRequest $request, TelegramUser $viewer): array
    {
        return array_column($this->presenter->detail($request, $viewer)['allowedTransitions'], 'value');
    }

    private function awaitingApproval(TelegramUser $worker, TelegramUser $manager): SupplyRequest
    {
        $request = $this->request($worker);

        ($this->changeStatus)($request, SupplyStatus::InProgress, $manager);
        ($this->recordPurchase)($request, $manager, new PurchaseInput(
            supplier: self::getContainer()->get(SupplierDirectory::class)->findOrCreate('ФОП Тест ' . uniqid()),
            totalAmount: '4200',
        ), notify: false);
        ($this->changeStatus)($request, SupplyStatus::Approval, $manager);

        return $request;
    }

    private function request(TelegramUser $author): SupplyRequest
    {
        return (self::getContainer()->get(CreateRequest::class))(
            $author,
            new CreateRequestInput(item: 'Цемент М400', quantity: '10', unit: Unit::Pack),
        );
    }

    /** @return array{0: TelegramUser, 1: TelegramUser, 2: TelegramUser} робітник, менеджер, директор */
    private function users(): array
    {
        $users = [];

        foreach ([SupplyRole::Worker, SupplyRole::Manager, SupplyRole::Director] as $role) {
            $user = (new TelegramUser())
                ->setTelegramId('roles-' . $role->value . '-' . uniqid())
                ->setFirstName($role->label())
                ->setSupplyRole($role);

            $this->em->persist($user);
            $users[] = $user;
        }

        $this->em->flush();

        return $users;
    }
}
