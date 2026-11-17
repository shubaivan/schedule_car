<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyRole;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Регресія стендів заявок (zayavky.*): дефолтні прапорці — без «Задач», без
 * складу, CRM з заявок, події синхронні. Заявка, статус і коментар через API
 * поводяться як до журналу: Telegram іде В ТОМУ Ж запиті, кожному — один раз;
 * журнал при цьому вже пишеться.
 */
class StandModeTest extends WebTestCase
{
    private const array ENV = [
        'TASKS_ENABLED' => '0',
        'WAREHOUSE_ENABLED' => '0',
        'CRM_HOME' => 'requests',
        'PLAN_EVENTS_TRANSPORT' => 'sync://',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private FakeNutgram $bot;
    /** @var array<string, array{0: mixed, 1: mixed}> */
    private array $saved = [];
    private int $seen = 0;

    protected function setUp(): void
    {
        foreach (self::ENV as $name => $value) {
            $this->saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
            $_SERVER[$name] = $_ENV[$name] = $value;
        }

        $this->client = self::createClient();
        // Один контейнер на весь сценарій — інакше підмінений бот зник би після першого запиту.
        $this->client->disableReboot();
        $this->bot = Nutgram::fake();
        self::getContainer()->set(Nutgram::class, $this->bot);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();

        foreach ($this->em->getRepository(TelegramUser::class)->findBy(['first_name' => 'Тест-Стенд']) as $user) {
            $conn->executeStatement('DELETE FROM plan_event WHERE actor_id = ?', [$user->getId()]);
            $conn->executeStatement('DELETE FROM supply_comment WHERE author_id = ?', [$user->getId()]);
            $conn->executeStatement('DELETE FROM supply_status_log WHERE author_id = ?', [$user->getId()]);
            $conn->executeStatement('DELETE FROM supply_request WHERE author_id = ?', [$user->getId()]);
            $conn->executeStatement('DELETE FROM telegram_user WHERE id = ?', [$user->getId()]);
        }

        foreach ($this->saved as $name => [$server, $env]) {
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }

            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
        }

        parent::tearDown();
    }

    public function testSupplyFlowBehavesAsBeforeTheJournal(): void
    {
        $worker = $this->person(SupplyRole::Worker);
        $manager = $this->person(SupplyRole::Manager);

        // Титулка заявок, не редірект у «План»; розділу задач немає.
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $this->client->loginUser($worker);
        $me = $this->json('GET', '/api/me');
        self::assertFalse($me['tasks']);
        self::assertSame('requests', $me['home']);
        self::assertFalse($me['warehouse']);

        $created = $this->json('POST', '/api/supply/requests', ['item' => 'Щебінь 5-20', 'quantity' => '10', 'unit' => 'ton']);
        $id = (int) $created['id'];
        $first = $this->sent();
        self::assertSame(1, $first[$worker->getTelegramId()] ?? 0, 'Заявнику — одне «Заявку прийнято» ще в цьому ж запиті');
        self::assertSame([1], array_values(array_unique($first)), 'Нікому двічі');

        $this->client->loginUser($manager);
        $this->json('POST', '/api/supply/requests/' . $id . '/status', ['to' => 'in_progress']);
        self::assertSame([$worker->getTelegramId() => 1], $this->sent());

        $this->json('POST', '/api/supply/requests/' . $id . '/comments', ['text' => 'Завтра привеземо']);
        self::assertSame([$worker->getTelegramId() => 1], $this->sent());

        // І журнал уже веде історію: три дії — три події, канал web.
        $events = $this->em->getConnection()->fetchAllAssociative(
            "SELECT type, channel FROM plan_event WHERE subject_type = 'supply' AND subject_id = ? ORDER BY id",
            [$id],
        );
        self::assertSame([
            ['type' => 'subject_created', 'channel' => 'web'],
            ['type' => 'status_changed', 'channel' => 'web'],
            ['type' => 'comment_added', 'channel' => 'web'],
        ], $events);

        self::assertNotNull($this->em->find(SupplyRequest::class, $id));
    }

    /** Стенди заявок (TASKS_ENABLED=0) — заявки відкриті всім допущеним, як і було (рішення 13.08). */
    public function testForeignRequestStaysVisibleOnTheStand(): void
    {
        $author = $this->person(SupplyRole::Worker);
        $other = $this->person(SupplyRole::Worker);

        $this->client->loginUser($author);
        $id = (int) $this->json('POST', '/api/supply/requests', ['item' => 'Пісок', 'quantity' => '1', 'unit' => 'ton'])['id'];
        $this->sent();

        $this->client->loginUser($other);
        self::assertContains($id, array_column($this->json('GET', '/api/supply/requests')['items'], 'id'));
        self::assertSame($id, (int) $this->json('GET', '/api/supply/requests/' . $id)['id']);
    }

    public function testTasksApiIsOffOnTheStand(): void
    {
        $this->client->loginUser($this->person(SupplyRole::Manager));
        $this->client->request('GET', '/api/tasks/board');

        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode(), 'TASKS_ENABLED=0 — API задач закрите');
    }

    /** Аудит і статус Диска — лише «План»: на стенді 404 навіть суперадміну й адміну заявок. */
    public function testPlanOnlyAuditAndDriveAre404OnTheStand(): void
    {
        $admin = $this->person(SupplyRole::Admin);
        $admin->setSuperadmin(true);
        $this->em->flush();
        $this->client->loginUser($admin);

        foreach (['GET /api/plan/audit', 'GET /api/plan/audit/meta', 'GET /api/plan/audit.csv', 'GET /api/plan/drive', 'POST /api/plan/drive/retry', 'POST /api/plan/drive/resume', 'GET /api/plan/notifications', 'GET /api/plan/presence', 'POST /api/plan/presence'] as $call) {
            [$method, $url] = explode(' ', $call);
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(404, $call);
        }
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function json(string $method, string $url, array $body = []): array
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: $body !== [] ? json_encode($body, JSON_THROW_ON_ERROR) : null);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @phpstan-impure
     *
     * @return array<string, int>
     */
    private function sent(): array
    {
        $count = [];
        $history = $this->bot->getRequestHistory();

        foreach (array_slice($history, $this->seen) as $pair) {
            [$request] = array_values($pair);
            $chat = (string) (FakeNutgram::getActualData($request)['chat_id'] ?? '');
            $count[$chat] = ($count[$chat] ?? 0) + 1;
        }

        $this->seen = count($history);
        ksort($count);

        return $count;
    }

    private function person(SupplyRole $role): TelegramUser
    {
        $user = new TelegramUser()->setPlanFullAccess(true)
            ->setTelegramId((string) random_int(100_000_000, 999_999_999))
            ->setFirstName('Тест-Стенд')
            ->setSupplyRole($role);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
