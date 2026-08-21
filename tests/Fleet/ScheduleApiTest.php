<?php

namespace App\Tests\Fleet;

use App\Entity\Car;
use App\Entity\CarDriver;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Service\CrmLoginLink;
use App\Supply\Enum\SupplyRole;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Календар завантаження в дашборді.
 *
 * Заводити машини й водіїв — право керівника, а бачити, чим зайнятий парк,
 * мусить кожен, хто планує поїздку: інакше людина йде домовлятись наосліп.
 * Тому цей маршрут навмисно відкритіший за решту автопарку.
 */
class ScheduleApiTest extends WebTestCase
{
    private const MARKER = 'Тест-Календар';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(Car::class)->findAll() as $car) {
            if (! str_starts_with((string) $car->getCarNumber(), 'КАЛ')) {
                continue;
            }

            foreach ($this->em->getRepository(ScheduledSet::class)->findBy(['car' => $car]) as $set) {
                $this->em->remove($set);
            }

            foreach ($this->em->getRepository(CarDriver::class)->findBy(['car' => $car]) as $link) {
                $this->em->remove($link);
            }

            $this->em->flush();
            $this->em->remove($car);
        }

        $this->em->flush();

        foreach ($this->em->getRepository(TelegramUser::class)->findBy(['first_name' => self::MARKER]) as $user) {
            $this->em->remove($user);
        }

        $this->em->flush();

        parent::tearDown();
    }

    public function testScheduleIsClosedWithoutLogin(): void
    {
        $this->client->request('GET', '/api/fleet/schedule');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** Довідник автопарку менеджеру закритий, а календар — ні. */
    public function testWorkerSeesLoadOfEveryCar(): void
    {
        $car = (new Car())->setCarNumber('КАЛ0001')->setModel('Renault Master');
        $this->em->persist($car);

        $driver = $this->person('Петро');
        $author = $this->person('Олександр');

        $link = new CarDriver();
        $link->setCar($car);
        $link->setDriver($driver);
        $this->em->persist($link);

        $at = (new DateTime('tomorrow', new DateTimeZone('Europe/Kyiv')))->setTime(8, 0);
        $this->em->persist(
            (new ScheduledSet())
                ->setCar($car)
                ->setTelegramUserId($author)
                ->setYear((int) $at->format('Y'))
                ->setMonth((int) $at->format('m'))
                ->setDay((int) $at->format('d'))
                ->setHour(8)
                ->setScheduledAt($at)
                ->setDestination('вул. Заводська, 5')
                ->setTask('Відвезти арматуру'),
        );
        $this->em->flush();

        $this->login(SupplyRole::Worker);
        $this->client->request('GET', '/api/fleet/schedule');

        self::assertResponseIsSuccessful();

        $board = json_decode((string) $this->client->getResponse()->getContent(), true);
        $row = $this->rowOf($board, 'КАЛ0001');

        self::assertCount(7, $board['days'], 'вікно календаря — тиждень');
        self::assertSame('Петро ' . self::MARKER, $row['drivers'][0]['name'], 'у рядку має бути водій машини');
        self::assertCount(1, $row['trips']);
        self::assertSame($at->format('Y-m-d'), $row['trips'][0]['date']);
        self::assertSame(8, $row['trips'][0]['hour']);
        self::assertSame('вул. Заводська, 5', $row['trips'][0]['destination'], 'куди їде машина');
        self::assertSame('Олександр ' . self::MARKER, $row['trips'][0]['bookedBy'], 'хто її забронював');
    }

    /** Вільна машина теж у відповіді: «на чому поїхати» — те саме питання. */
    public function testFreeCarStaysInTheBoardWithoutTrips(): void
    {
        $this->em->persist((new Car())->setCarNumber('КАЛ0002'));
        $this->em->flush();

        $this->login(SupplyRole::Worker);
        $this->client->request('GET', '/api/fleet/schedule');

        $board = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame([], $this->rowOf($board, 'КАЛ0002')['trips']);
    }

    private function rowOf(array $board, string $carNumber): array
    {
        foreach ($board['items'] as $row) {
            if ($row['carNumber'] === $carNumber) {
                return $row;
            }
        }

        self::fail(sprintf('машини %s немає в календарі', $carNumber));
    }

    private function person(string $name): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('board-' . uniqid())
            ->setFirstName($name . ' ' . self::MARKER)
            ->setPhoneNumber('380631110000');

        $this->em->persist($user);

        return $user;
    }

    private function login(SupplyRole $role): void
    {
        $user = (new TelegramUser())
            ->setTelegramId('board-login-' . uniqid())
            ->setFirstName(self::MARKER)
            ->setSupplyRole($role);

        $this->em->persist($user);
        $this->em->flush();

        $url = self::getContainer()->get(CrmLoginLink::class)->issue($user);
        $this->client->request('GET', '/crm/auth/' . substr($url, strrpos($url, '/') + 1));
    }
}
