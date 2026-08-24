<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Service\CrmLoginLink;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Entity\Department;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\Unit;
use App\Supply\Service\CreateRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** Вкладка «Підрозділи»: менеджер веде список сам, без консолі. */
class DepartmentApiTest extends WebTestCase
{
    private const PREFIX = 'depts-test-';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(TelegramUser::class)->findAll() as $user) {
            if (! str_starts_with((string) $user->getTelegramId(), self::PREFIX)) {
                continue;
            }

            foreach ($this->em->getRepository(SupplyRequest::class)->findBy(['author' => $user]) as $request) {
                $this->em->remove($request);
            }

            $this->em->remove($user);
        }

        $this->em->flush();

        foreach ($this->em->getRepository(Department::class)->findAll() as $department) {
            if (str_starts_with($department->getName(), 'Тест-цех')) {
                $this->em->remove($department);
            }
        }

        $this->em->flush();

        parent::tearDown();
    }

    public function testManagerCreatesRenamesAndDeletes(): void
    {
        $this->login(SupplyRole::Manager);

        $created = $this->send('POST', '/api/supply/departments', ['name' => 'Тест-цех ' . uniqid()]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertTrue($created['active']);
        self::assertSame(0, $created['requests']);

        $renamed = $this->send('PATCH', '/api/supply/departments/' . $created['id'], ['name' => 'Тест-цех новий']);
        self::assertSame('Тест-цех новий', $renamed['name']);

        $deleted = $this->send('DELETE', '/api/supply/departments/' . $created['id']);
        self::assertTrue($deleted['deleted']);
    }

    public function testDuplicateNameIsRefused(): void
    {
        $this->login(SupplyRole::Manager);
        $name = 'Тест-цех ' . uniqid();

        $this->send('POST', '/api/supply/departments', ['name' => $name]);
        $this->send('POST', '/api/supply/departments', ['name' => $name]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** Історію не стираємо: підрозділ із заявками лише зникає зі списку вибору. */
    public function testDepartmentWithRequestsIsHiddenNotDeleted(): void
    {
        $department = (new Department())->setName('Тест-цех ' . uniqid());
        $this->em->persist($department);
        $this->em->flush();

        $worker = $this->user(SupplyRole::Worker);
        (self::getContainer()->get(CreateRequest::class))(
            $worker,
            new CreateRequestInput(item: 'Фарба', quantity: '2', unit: Unit::Piece, department: $department),
        );

        $this->login(SupplyRole::Manager);

        $payload = $this->send('DELETE', '/api/supply/departments/' . $department->getId());

        self::assertFalse($payload['deleted']);

        // Зі списку вибору зник, а в довіднику лишився — разом із лічильником заявок.
        $items = $this->send('GET', '/api/supply/departments');
        $row = current(array_filter($items['items'], static fn (array $i) => $i['id'] === $department->getId()));
        self::assertFalse($row['active']);
        self::assertSame(1, $row['requests']);
    }

    public function testWorkerCannotEditDirectory(): void
    {
        $this->login(SupplyRole::Worker);

        $this->client->request('GET', '/api/supply/departments');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function send(string $method, string $url, ?array $payload = null): array
    {
        $this->client->request(
            $method,
            $url,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $payload !== null ? json_encode($payload) : null,
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function login(SupplyRole $role): void
    {
        $user = $this->user($role);
        $url = self::getContainer()->get(CrmLoginLink::class)->issue($user);
        $this->client->request('GET', '/crm/auth/' . substr($url, strrpos($url, '/') + 1));
    }

    private function user(SupplyRole $role): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId(self::PREFIX . uniqid())
            ->setFirstName('Тест-Підрозділи')
            ->setSupplyRole($role);
        $user->decideAccess(AccessStatus::Approved, null);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
