<?php

namespace App\Tests\Fleet;

use App\Entity\Car;
use App\Entity\DriverPhone;
use App\Entity\TelegramUser;
use App\Service\CrmLoginLink;
use App\Supply\Enum\SupplyRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Автопарк у дашборді. Машини й людей роздає керівник, тому поріг тут вищий,
 * ніж у заявках: менеджера з постачання сюди не пускаємо.
 */
class FleetApiTest extends WebTestCase
{
    private const MARKER = 'Тест-Автопарк';
    private const CAR = 'ТЕСТ0001';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(DriverPhone::class)->findAll() as $entry) {
            if ($entry->getName() !== null && str_contains($entry->getName(), self::MARKER)) {
                $this->em->remove($entry);
            }
        }

        $this->em->flush();

        foreach ($this->em->getRepository(Car::class)->findAll() as $car) {
            if (str_starts_with((string) $car->getCarNumber(), 'ТЕСТ')) {
                $this->em->remove($car);
            }
        }

        foreach ($this->em->getRepository(TelegramUser::class)->findBy(['first_name' => self::MARKER]) as $user) {
            $this->em->remove($user);
        }

        $this->em->flush();

        parent::tearDown();
    }

    public function testFleetIsClosedWithoutLogin(): void
    {
        $this->client->request('GET', '/api/fleet/cars');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** Менеджер із постачання веде заявки, а не автопарк. */
    public function testSupplyManagerCannotManageFleet(): void
    {
        $this->login(SupplyRole::Manager);

        $this->client->request('GET', '/api/fleet/cars');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAdminAddsCarAndDriver(): void
    {
        $this->login(SupplyRole::Admin);

        $car = $this->send('POST', '/api/fleet/cars', ['carNumber' => self::CAR, 'model' => 'Renault Master']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(self::CAR . ' · Renault Master', $car['label']);

        $driver = $this->send('POST', '/api/fleet/drivers', [
            'phone' => '0631119988',
            'name' => self::MARKER . ' Петро',
            'carId' => $car['id'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        // Зберігаємо як записали, лише цифрами; збіг шукаємо за останніми
        // девʼятьма — тож 067…, +38067… і 0…  це той самий водій.
        self::assertSame('0631119988', $driver['phone']);
        self::assertSame($car['label'], $driver['carLabel']);
        // Людина ще не заходила в бота — запис чекає на неї.
        self::assertNull($driver['appliedTo']);
    }

    public function testDuplicateCarNumberIsRefusedWithReadableError(): void
    {
        $this->login(SupplyRole::Admin);

        $this->send('POST', '/api/fleet/cars', ['carNumber' => self::CAR]);
        $error = $this->send('POST', '/api/fleet/cars', ['carNumber' => self::CAR]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('уже є в списку', $error['error']);
    }

    public function testShortPhoneIsRefused(): void
    {
        $this->login(SupplyRole::Admin);

        $error = $this->send('POST', '/api/fleet/drivers', [
            'phone' => '12345',
            'name' => self::MARKER . ' Короткий',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('щонайменше 9 цифр', $error['error']);
    }

    private function send(string $method, string $url, array $payload = []): array
    {
        $this->client->request(
            $method,
            $url,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function login(SupplyRole $role): void
    {
        $user = (new TelegramUser())
            ->setTelegramId('fleet-api-' . uniqid())
            ->setFirstName(self::MARKER)
            ->setSupplyRole($role);

        $this->em->persist($user);
        $this->em->flush();

        $url = self::getContainer()->get(CrmLoginLink::class)->issue($user);
        $this->client->request('GET', '/crm/auth/' . substr($url, strrpos($url, '/') + 1));
    }
}
