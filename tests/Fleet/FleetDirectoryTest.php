<?php

namespace App\Tests\Fleet;

use App\Entity\Car;
use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Repository\CarDriverRepository;
use App\Service\AccessService;
use App\Service\FleetDirectory;
use App\Supply\Exception\SupplyException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Автопарк: керівник вносить машину й телефон водія заздалегідь, а прив'язка
 * спрацьовує сама — коли людина натисне «Старт» у боті або, якщо вона там уже
 * є, одразу під час збереження запису.
 */
class FleetDirectoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FleetDirectory $fleet;
    private AccessService $access;
    private CarDriverRepository $carDrivers;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->fleet = self::getContainer()->get(FleetDirectory::class);
        $this->access = self::getContainer()->get(AccessService::class);
        $this->carDrivers = self::getContainer()->get(CarDriverRepository::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testDriverBecomesLinkedWhenHeRegistersInBot(): void
    {
        $car = $this->car('AA1111BB');
        $this->fleet->saveDriver(null, ['phone' => '+380631112255', 'name' => 'Петро', 'carId' => $car->getId()]);

        $user = $this->user();
        $this->access->registerPhone($user, '+380631112255');

        // Незнайомець чекав би підтвердження; водія вніс керівник — доступ одразу.
        self::assertSame(AccessStatus::Approved, $user->getAccessStatus());
        $link = $this->carDrivers->findOneByDriver($user);
        self::assertNotNull($link, 'водія не прив’язали до машини');
        self::assertSame($car->getId(), $link->getCar()->getId());
    }

    /** Людина вже в боті — чекати другої реєстрації не треба. */
    public function testDriverAlreadyInBotIsLinkedOnSave(): void
    {
        $user = $this->user();
        $this->access->registerPhone($user, '+380639990011');

        $car = $this->car('AA2222BB');
        $entry = $this->fleet->saveDriver(null, ['phone' => '0639990011', 'carId' => $car->getId()]);

        self::assertSame($user->getId(), $entry->getAppliedTo()?->getId());
        self::assertNotNull($this->carDrivers->findOneByDriver($user));
    }

    /** Той самий номер у різних форматах — один водій, а не три. */
    public function testSamePhoneUpdatesTheSameDriver(): void
    {
        $first = $this->fleet->saveDriver(null, ['phone' => '0631112277', 'name' => 'Петро']);
        $again = $this->fleet->saveDriver(null, ['phone' => '+38 (063) 111-22-77', 'name' => 'Петро Іванович']);

        self::assertSame($first->getId(), $again->getId());
        self::assertSame('Петро Іванович', $again->getName());
    }

    public function testCarNumberMustBeUnique(): void
    {
        $this->car('AA3333BB');

        $this->expectException(SupplyException::class);
        $this->fleet->saveCar(null, ['carNumber' => 'AA3333BB']);
    }

    /** Прибрали водія з довідника — знімається й прив'язка до машини. */
    public function testRemovingDriverUnlinksHimFromCar(): void
    {
        $car = $this->car('AA4444BB');
        $entry = $this->fleet->saveDriver(null, ['phone' => '0631112288', 'carId' => $car->getId()]);

        $user = $this->user();
        $this->access->registerPhone($user, '0631112288');

        $this->fleet->removeDriver($entry);

        self::assertNull($this->carDrivers->findOneByDriver($user));
    }

    private function car(string $number): Car
    {
        return $this->fleet->saveCar(null, ['carNumber' => $number, 'model' => 'Renault Master']);
    }

    private function user(): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('fleet-' . uniqid())
            ->setFirstName('Водій');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
