<?php

namespace App\Tests\Plan\Notification;

use App\Entity\TelegramUser;
use App\Plan\Realtime\MercurePublisher;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Enum\WorkRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;

/**
 * Стенди заявок (TASKS_ENABLED=0, бот заявок): сповіщення Плану їх не
 * торкаються — ні рядків центру, ні Mercure, API центру закрите, а Telegram
 * іде тим самим ботом із тими самими кнопками заявок, що й раніше.
 */
class StandModeNotificationsTest extends WebTestCase
{
    use NotificationFixtures;

    private const array ENV = ['TASKS_ENABLED' => '0', 'WAREHOUSE_ENABLED' => '0', 'CRM_HOME' => 'requests', 'PLAN_EVENTS_TRANSPORT' => 'sync://', 'PLAN_TELEGRAM_BOT' => '0'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private FakeNutgram $bot;
    /** @var array<string, array{0: mixed, 1: mixed}> */
    private array $saved = [];
    private int $published = 0;
    private int $seen = 0;

    protected function setUp(): void
    {
        foreach (self::ENV as $name => $value) {
            $this->saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
            $_SERVER[$name] = $_ENV[$name] = $value;
        }

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->bot = Nutgram::fake();
        self::getContainer()->set(Nutgram::class, $this->bot);
        self::getContainer()->set(MercurePublisher::class, new MercurePublisher(
            new MockHub('http://hub.test/.well-known/mercure', new StaticTokenProvider('t'), function (): string {
                ++$this->published;

                return 'id';
            }),
            new NullLogger(),
            'http://hub.test/.well-known/mercure',
        ));
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();

        foreach ($this->em->getRepository(TelegramUser::class)->findBy(['first_name' => 'Тест-Стенд-S1']) as $user) {
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

    protected function em(): EntityManagerInterface
    {
        return $this->em;
    }

    public function testStandKeepsItsBotAndGetsNoPlanNotifications(): void
    {
        $worker = $this->person(WorkRole::Production, SupplyRole::Worker, name: 'Тест-Стенд-S1');
        $manager = $this->person(WorkRole::Manager, SupplyRole::Manager, name: 'Тест-Стенд-S1');

        $this->client->loginUser($worker);
        $this->client->request('POST', '/api/supply/requests', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['item' => 'Щебінь 5-20', 'quantity' => '10', 'unit' => 'ton']));
        self::assertResponseIsSuccessful();
        $id = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $sent = $this->telegram($this->bot, $this->seen);
        $toWorker = array_values(array_filter($sent, static fn (array $m) => (string) $m['chat_id'] === $worker->getTelegramId()));
        self::assertCount(1, $toWorker, 'Заявнику — одне «Заявку прийнято», як і раніше');
        self::assertStringContainsString('callback_data', (string) json_encode($toWorker[0]['reply_markup'] ?? null), 'Кнопки бота заявок (коментар, мої заявки) на місці');
        self::assertArrayNotHasKey('disable_notification', array_filter($toWorker[0], static fn ($v) => $v !== null));

        $this->client->loginUser($manager);
        $this->client->request('POST', '/api/supply/requests/' . $id . '/comments', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['text' => 'Завтра']));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->telegram($this->bot, $this->seen));

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM notification n JOIN plan_event e ON e.id = n.event_id WHERE e.subject_type = 'supply' AND e.subject_id = ?",
            [$id],
        ), 'Стенд не пише рядків центру сповіщень');
        self::assertSame(0, $this->published, 'І не публікує в Mercure');

        $this->client->request('GET', '/api/plan/notifications/count');
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'API центру на стенді закрите');
    }
}
