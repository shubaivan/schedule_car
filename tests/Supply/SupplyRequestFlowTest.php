<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Entity\Department;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Exception\SupplyException;
use App\Supply\Service\AddComment;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\CreateRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Життєвий цикл заявки: створення → статуси → коментарі.
 * Сповіщення в Telegram усередині сервісів ловлять власні винятки,
 * тож тест проходить без токена бота.
 */
class SupplyRequestFlowTest extends KernelTestCase
{
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

    public function testFullLifecycle(): void
    {
        [$worker, $manager] = $this->users();

        $request = (self::getContainer()->get(CreateRequest::class))(
            $worker,
            new CreateRequestInput(
                item: 'Арматура 12 А500С',
                quantity: '2.5',
                unit: Unit::Ton,
                needBy: new \DateTime('+3 days'),
                urgent: false,
                note: 'На фундамент цеху №2',
            ),
        );

        self::assertMatchesRegularExpression('~^\d{3}/\d{4}$~', $request->getNumber());
        self::assertSame(SupplyStatus::New, $request->getStatus());
        self::assertSame('2.5 т', $request->getQuantityLabel());
        self::assertCount(1, $request->getStatusLogs(), 'створення теж пишеться в аудит');

        $changeStatus = self::getContainer()->get(ChangeStatus::class);

        $changeStatus($request, SupplyStatus::InProgress, $manager);
        $changeStatus($request, SupplyStatus::Paid, $manager);
        $changeStatus($request, SupplyStatus::Delivery, $manager);
        $changeStatus($request, SupplyStatus::InStock, $manager);

        self::assertSame(SupplyStatus::InStock, $request->getStatus());
        self::assertNotNull($request->getClosedAt(), 'закрита заявка має дату закриття');
        self::assertCount(5, $request->getStatusLogs());
    }

    public function testForbiddenTransitionIsRejected(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->newRequest($worker);

        $this->expectException(SupplyException::class);

        // З «Нова» одразу в «Доставка» не можна — заявку спершу беруть у роботу.
        (self::getContainer()->get(ChangeStatus::class))($request, SupplyStatus::Delivery, $manager);
    }

    public function testWorkerCannotChangeStatus(): void
    {
        [$worker] = $this->users();
        $request = $this->newRequest($worker);

        $this->expectException(SupplyException::class);

        (self::getContainer()->get(ChangeStatus::class))($request, SupplyStatus::InProgress, $worker);
    }

    public function testRejectionRequiresReason(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->newRequest($worker);

        $this->expectException(SupplyException::class);

        (self::getContainer()->get(ChangeStatus::class))($request, SupplyStatus::Rejected, $manager);
    }

    public function testRejectionWithReasonIsLogged(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->newRequest($worker);

        (self::getContainer()->get(ChangeStatus::class))(
            $request,
            SupplyStatus::Rejected,
            $manager,
            'Є на складі, зверніться до комірника',
        );

        self::assertSame(SupplyStatus::Rejected, $request->getStatus());

        $last = $request->getStatusLogs()->last();
        self::assertSame('Є на складі, зверніться до комірника', $last->getComment());
    }

    public function testCommentsFromBothSides(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->newRequest($worker);

        $addComment = self::getContainer()->get(AddComment::class);
        $addComment($request, $worker, 'Дуже потрібно до п’ятниці');
        $addComment($request, $manager, 'Шукаю постачальника');

        self::assertCount(2, $request->getComments());
    }

    public function testStrangerCannotComment(): void
    {
        [$worker] = $this->users();
        $request = $this->newRequest($worker);

        $stranger = $this->user('stranger', SupplyRole::Worker);

        $this->expectException(SupplyException::class);

        (self::getContainer()->get(AddComment::class))($request, $stranger, 'а що тут?');
    }

    /**
     * Дати в БД лежать без таймзони, тож застосунок мусить писати й читати їх
     * в одному поясі. Інакше свіжий об'єкт і той самий об'єкт із бази
     * показують різний час — саме це й ловить цей тест.
     */
    public function testTimestampsSurviveReload(): void
    {
        [$worker] = $this->users();
        $request = $this->newRequest($worker);

        $createdAt = $request->getCreatedAt()->format(DATE_ATOM);
        $id = $request->getId();

        $this->em->clear();

        $reloaded = $this->em->getRepository(SupplyRequest::class)->find($id);

        self::assertSame($createdAt, $reloaded->getCreatedAt()->format(DATE_ATOM));
    }

    /**
     * Кількість у картці: «40» має лишитись сорока, а не перетворитись на «4».
     * Значення приходить і як ціле з бота, і як NUMERIC(12,3) з бази.
     *
     * @dataProvider quantityLabels
     */
    public function testQuantityLabel(string $stored, string $expected): void
    {
        $request = (new SupplyRequest())->setQuantity($stored)->setUnit(Unit::Piece);

        self::assertSame($expected, $request->getQuantityLabel());
    }

    public static function quantityLabels(): array
    {
        return [
            'ціле з бота' => ['40', '40 шт'],
            'ціле з бази' => ['40.000', '40 шт'],
            'сотня' => ['100', '100 шт'],
            'дробове з бота' => ['2.5', '2.5 шт'],
            'дробове з бази' => ['2.500', '2.5 шт'],
            'менше одиниці' => ['0.500', '0.5 шт'],
        ];
    }

    /** @return TelegramUser[] */
    private function users(): array
    {
        $department = (new Department())->setName('Цех №2 ' . uniqid());
        $this->em->persist($department);

        $worker = $this->user('worker', SupplyRole::Worker)->setDepartment($department);
        $manager = $this->user('manager', SupplyRole::Manager);

        $this->em->flush();

        return [$worker, $manager];
    }

    private function user(string $prefix, SupplyRole $role): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId($prefix . '-' . uniqid())
            ->setFirstName(ucfirst($prefix))
            ->setLanguageCode('uk')
            ->setSupplyRole($role);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function newRequest(TelegramUser $author): SupplyRequest
    {
        return (self::getContainer()->get(CreateRequest::class))(
            $author,
            new CreateRequestInput(item: 'Цемент М400', quantity: '10', unit: Unit::Pack),
        );
    }
}
