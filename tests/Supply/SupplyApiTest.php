<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Service\CrmLoginLink;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Entity\Department;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Service\CreateRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** API столу заявок: доступ, фільтри, зміна статусу, коментарі. */
class SupplyApiTest extends WebTestCase
{
    private const MARKER = 'Тест-API';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(TelegramUser::class)->findBy(['first_name' => self::MARKER]) as $user) {
            foreach ($this->em->getRepository(SupplyRequest::class)->findBy(['author' => $user]) as $request) {
                $this->em->remove($request);
            }
            $this->em->remove($user);
        }
        $this->em->flush();

        parent::tearDown();
    }

    public function testApiIsClosedWithoutLogin(): void
    {
        $this->client->request('GET', '/api/supply/requests');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testListDetailStatusAndComment(): void
    {
        $worker = $this->user(SupplyRole::Worker);
        $request = $this->request($worker);

        $this->loginAsManager();

        // Список
        $items = $this->get('/api/supply/requests?q=' . urlencode('Пісок'));
        self::assertGreaterThanOrEqual(1, $items['total']);
        self::assertSame('new', $items['items'][0]['status']);
        self::assertArrayHasKey('counts', $items);

        // Картка: хронологія містить створення
        $detail = $this->get('/api/supply/requests/' . $request->getId());
        self::assertSame($request->getNumber(), $detail['number']);
        self::assertSame('5 м³', $detail['quantityLabel']);
        self::assertCount(1, $detail['timeline']);
        self::assertSame(
            ['in_progress', 'rejected'],
            array_column($detail['allowedTransitions'], 'value'),
        );

        // Зміна статусу
        $updated = $this->post('/api/supply/requests/' . $request->getId() . '/status', ['to' => 'in_progress']);
        self::assertSame('in_progress', $updated['status']);
        self::assertCount(2, $updated['timeline']);

        // Коментар потрапляє в ту саму хронологію
        $commented = $this->post(
            '/api/supply/requests/' . $request->getId() . '/comments',
            ['text' => 'Знайшов постачальника'],
        );
        self::assertCount(3, $commented['timeline']);
        self::assertSame('comment', $commented['timeline'][2]['type']);
    }

    public function testForbiddenTransitionReturns422(): void
    {
        $request = $this->request($this->user(SupplyRole::Worker));
        $this->loginAsManager();

        $this->client->request(
            'POST',
            '/api/supply/requests/' . $request->getId() . '/status',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['to' => SupplyStatus::Delivery->value]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertArrayHasKey('error', json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testRejectWithoutReasonReturns422(): void
    {
        $request = $this->request($this->user(SupplyRole::Worker));
        $this->loginAsManager();

        $this->client->request(
            'POST',
            '/api/supply/requests/' . $request->getId() . '/status',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['to' => SupplyStatus::Rejected->value]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testManagerCannotChangeRoles(): void
    {
        $worker = $this->user(SupplyRole::Worker);
        $this->loginAsManager();

        $this->client->request(
            'PATCH',
            '/api/supply/users/' . $worker->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['role' => 'admin']),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAdminAssignsRoleAndDepartment(): void
    {
        $worker = $this->user(SupplyRole::Worker);

        $department = (new Department())->setName('Дільниця ' . uniqid());
        $this->em->persist($department);
        $this->em->flush();

        $this->login($this->user(SupplyRole::Admin));

        $this->client->request(
            'PATCH',
            '/api/supply/users/' . $worker->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['role' => 'manager', 'departmentId' => $department->getId()]),
        );

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('manager', $payload['role']);
        self::assertSame($department->getName(), $payload['department']);
    }

    public function testMetaReturnsDictionaries(): void
    {
        $this->loginAsManager();

        $meta = $this->get('/api/supply/meta');

        self::assertCount(6, $meta['statuses']);
        self::assertCount(7, $meta['units']);
        self::assertArrayHasKey('departments', $meta);
    }

    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function post(string $url, array $payload): array
    {
        $this->client->request(
            'POST',
            $url,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload),
        );
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function loginAsManager(): void
    {
        $this->login($this->user(SupplyRole::Manager));
    }

    private function login(TelegramUser $user): void
    {
        $url = self::getContainer()->get(CrmLoginLink::class)->issue($user);
        $this->client->request('GET', '/crm/auth/' . substr($url, strrpos($url, '/') + 1));
    }

    private function request(TelegramUser $author): SupplyRequest
    {
        return (self::getContainer()->get(CreateRequest::class))(
            $author,
            new CreateRequestInput(item: 'Пісок річковий', quantity: '5', unit: Unit::CubicMeter),
        );
    }

    private function user(SupplyRole $role): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('api-' . uniqid())
            ->setFirstName(self::MARKER)
            ->setSupplyRole($role);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
