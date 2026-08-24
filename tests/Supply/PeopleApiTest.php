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

/**
 * Вкладка «Люди»: менеджер погоджує доступ, править картку і прибирає зайвих.
 * Вимога клієнта від 21.08.2026 — до цього все це міг лише адміністратор.
 */
class PeopleApiTest extends WebTestCase
{
    private const PREFIX = 'people-test-';

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

        parent::tearDown();
    }

    public function testManagerApprovesAccess(): void
    {
        $newcomer = $this->user(SupplyRole::Worker);
        $this->login($this->user(SupplyRole::Manager));

        $payload = $this->post('/api/supply/users/' . $newcomer->getId() . '/access', ['status' => 'approved']);

        self::assertSame('approved', $payload['accessStatus']);
        self::assertSame(AccessStatus::Approved, $this->fresh($newcomer)->getAccessStatus());
    }

    public function testManagerEditsNameAndDepartment(): void
    {
        $worker = $this->user(SupplyRole::Worker);
        $department = $this->department();
        $this->login($this->user(SupplyRole::Manager));

        $this->client->request(
            'PATCH',
            '/api/supply/users/' . $worker->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'firstName' => 'Оксана',
                'lastName' => 'Петренко',
                'departmentId' => $department->getId(),
            ]),
        );

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Оксана Петренко', $payload['name']);
        self::assertSame($department->getName(), $payload['department']);
        // Телефон не редагується навмисно: за ним людина чіпляється при реєстрації.
        self::assertSame($worker->getPhoneNumber(), $payload['phone']);
    }

    public function testPersonWithoutHistoryIsDeletedForGood(): void
    {
        $worker = $this->user(SupplyRole::Worker);
        $id = $worker->getId();
        $this->login($this->user(SupplyRole::Manager));

        $payload = $this->delete('/api/supply/users/' . $id);

        self::assertTrue($payload['deleted']);
        $this->em->clear();
        self::assertNull($this->em->getRepository(TelegramUser::class)->find($id));
    }

    /** Заявки не мають зникати разом із людиною — така людина йде в архів. */
    public function testPersonWithRequestsGoesToArchive(): void
    {
        $worker = $this->user(SupplyRole::Worker);
        (self::getContainer()->get(CreateRequest::class))(
            $worker,
            new CreateRequestInput(item: 'Цвяхи', quantity: '3', unit: Unit::Kg),
        );

        $this->login($this->user(SupplyRole::Manager));

        $payload = $this->delete('/api/supply/users/' . $worker->getId());

        self::assertFalse($payload['deleted']);

        $stored = $this->fresh($worker);
        self::assertTrue($stored->isArchived());
        self::assertTrue($stored->isRejected());

        // Зі списку зник, але за окремим запитом його видно.
        $visible = array_column($this->get('/api/supply/users')['items'], 'id');
        self::assertNotContains($worker->getId(), $visible);

        $archived = array_column($this->get('/api/supply/users?archived=1')['items'], 'id');
        self::assertContains($worker->getId(), $archived);
    }

    public function testWorkerCannotTouchThePeopleList(): void
    {
        $someone = $this->user(SupplyRole::Worker);
        $this->login($this->user(SupplyRole::Worker));

        $this->client->request('GET', '/api/supply/users');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('DELETE', '/api/supply/users/' . $someone->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Свіжий стан із бази: тестовий клієнт перезапускає ядро на кожен запит,
     * тож об'єкт, створений до нього, лишається зі старими значеннями.
     */
    private function fresh(TelegramUser $user): TelegramUser
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(TelegramUser::class)->find($user->getId());
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

    private function delete(string $url): array
    {
        $this->client->request('DELETE', $url);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function login(TelegramUser $user): void
    {
        $url = self::getContainer()->get(CrmLoginLink::class)->issue($user);
        $this->client->request('GET', '/crm/auth/' . substr($url, strrpos($url, '/') + 1));
    }

    private function department(): Department
    {
        $department = (new Department())->setName('Дільниця ' . uniqid());

        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    private function user(SupplyRole $role): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId(self::PREFIX . uniqid())
            ->setFirstName('Тест-Люди')
            ->setPhoneNumber('38050' . random_int(1000000, 9999999))
            ->setSupplyRole($role);

        if ($role !== SupplyRole::Worker) {
            $user->decideAccess(AccessStatus::Approved, null);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
