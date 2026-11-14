<?php

namespace App\Tests\Plan\Notification;

use App\Entity\TelegramUser;
use App\Plan\Realtime\MercurePublisher;
use App\Plan\Telegram\LinkResult;
use App\Plan\Telegram\TelegramLink;
use App\Tasks\Entity\Task;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\TaskService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\ProtocolVersion;

/** API центру сповіщень: своє й лише своє; налаштування; живий канал; бот і push. */
class NotificationApiTest extends WebTestCase
{
    use NotificationFixtures;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private TaskService $tasks;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set(Nutgram::class, Nutgram::fake());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->tasks = self::getContainer()->get(TaskService::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    protected function em(): EntityManagerInterface
    {
        return $this->em;
    }

    public function testInboxShowsOwnNotificationsAndMarksThemRead(): void
    {
        $creator = $this->person(WorkRole::Manager, name: 'Олена');
        $member = $this->person();
        $other = $this->person();
        $task = $this->tasks->create($creator, ['title' => 'Плити П-3', 'memberIds' => [$member->getId()]]);
        $this->tasks->post($task, $creator, 'Розміри уточнила', mentions: [(int) $member->getId()]);
        $this->tasks->changeStatus($task, $creator, TaskStatus::InWork);

        $this->client->loginUser($member);
        $page = $this->json('GET', '/api/plan/notifications');
        self::assertSame(3, $page['unread']);
        self::assertCount(3, $page['items']);
        [$status, $mention] = $page['items'];
        self::assertSame('mention', $mention['group']);
        self::assertTrue($mention['important']);
        self::assertSame('Вас згадали', $mention['headline']);
        self::assertSame('Олена: Розміри уточнила', $mention['detail']);
        self::assertSame('task-' . $task->getId(), $mention['subject']['key']);
        self::assertStringContainsString('Плити П-3', $mention['title']);
        self::assertSame('/crm/tasks/board/' . $task->getId(), $mention['url']);
        self::assertSame('status', $status['group']);
        self::assertStringContainsString('→', $status['headline']);

        $events = $this->json('GET', '/api/plan/notifications/events?after=' . ($mention['event'] - 1))['events'];
        self::assertSame([$mention['event'], $status['event']], array_column($events, 'event'));
        self::assertSame(['event', 'type', 'subject', 'unread', 'important'], array_keys($events[0]), 'Опитування віддає те саме, що й Mercure');

        self::assertSame(2, $this->json('POST', '/api/plan/notifications/' . $mention['id'] . '/read')['unread']);
        self::assertSame(2, $this->json('GET', '/api/plan/notifications?unread=1')['unread']);

        // Чуже сповіщення не прочитати й не побачити.
        $this->client->loginUser($other);
        self::assertSame(0, $this->json('GET', '/api/plan/notifications/count')['unread']);
        $this->json('POST', '/api/plan/notifications/' . $status['id'] . '/read');
        $this->client->request('GET', '/api/plan/notifications/event/' . $status['event']);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($member);
        self::assertSame(2, $this->json('GET', '/api/plan/notifications/count')['unread'], 'Чужий клік нічого не змінив');
        self::assertSame(0, $this->json('POST', '/api/plan/notifications/read-subject', ['subject' => 'task-' . $task->getId()])['unread']);
        // Після запитів клієнта EntityManager очищено — беремо свіжі сутності.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(TaskService::class)->post($em->find(Task::class, $task->getId()), $em->find(TelegramUser::class, $creator->getId()), 'Ще одне');
        self::assertSame(1, $this->json('GET', '/api/plan/notifications/count')['unread']);
        self::assertSame(0, $this->json('POST', '/api/plan/notifications/read-all')['unread']);
    }

    public function testSettingsRoundTripAndScopeAllOnlyForDirectors(): void
    {
        $worker = $this->person();
        $worker->setTelegramId('staff-' . bin2hex(random_bytes(6)));
        $this->em->flush();
        $this->client->loginUser($worker);

        $view = $this->json('GET', '/api/plan/notifications/settings');
        self::assertFalse($view['canWatchAll']);
        self::assertTrue($view['prefs']['groups']['comment']['telegram']);
        self::assertSame('testbot', $view['telegram']['bot']);
        self::assertFalse($view['telegram']['linked']);
        self::assertNull($view['push']['publicKey'], 'Без VAPID-ключів push вимкнено');

        $saved = $this->json('PUT', '/api/plan/notifications/settings', [
            'scope' => 'all',
            'sound' => true,
            'quietFrom' => '22:00',
            'quietTo' => '08:00',
            'groups' => ['comment' => ['telegram' => false, 'push' => false], 'bogus' => ['app' => false]],
        ])['prefs'];
        self::assertSame('mine', $saved['scope'], 'Робітник «Усе» не вмикає');
        self::assertTrue($saved['sound']);
        self::assertSame(['22:00', '08:00'], [$saved['quietFrom'], $saved['quietTo']]);
        self::assertFalse($saved['groups']['comment']['telegram']);
        self::assertFalse($saved['groups']['comment']['push']);
        self::assertTrue($saved['groups']['comment']['app']);

        $director = $this->person(WorkRole::Director);
        $this->client->loginUser($director);
        self::assertTrue($this->json('GET', '/api/plan/notifications/settings')['canWatchAll']);
        self::assertSame('all', $this->json('PUT', '/api/plan/notifications/settings', ['scope' => 'all'])['prefs']['scope']);

        $link = $this->json('POST', '/api/plan/telegram/link');
        self::assertStringStartsWith('https://t.me/testbot?start=plink_', $link['url']);

        $this->client->request('POST', '/api/plan/push/subscriptions', server: ['CONTENT_TYPE' => 'application/json'], content: '{"endpoint":"https://push.test/x","keys":{"p256dh":"a","auth":"b"}}');
        self::assertResponseStatusCodeSame(409, 'Push без ключів сервера — зрозуміла відмова');
    }

    /** Прив'язка бота змінює telegram_id — веб-сесія від цього не обривається. */
    public function testLinkingTelegramKeepsTheWebSession(): void
    {
        $user = $this->person(WorkRole::Manager);
        $user->setTelegramId('staff-' . bin2hex(random_bytes(6)));
        $this->em->flush();
        $this->client->loginUser($user);
        $url = $this->json('POST', '/api/plan/telegram/link')['url'];

        [$result] = self::getContainer()->get(TelegramLink::class)->consume(substr($url, -32), '700100900', '700100900');
        self::assertSame(LinkResult::Linked, $result);

        $view = $this->json('GET', '/api/plan/notifications/settings');
        self::assertTrue($view['telegram']['linked'], 'Та сама сесія, тепер із прив\'язаним Telegram');
    }

    public function testRealtimeFallsBackToPollingWithoutHub(): void
    {
        $this->client->loginUser($this->person());

        $poll = $this->json('GET', '/api/plan/realtime');
        self::assertSame('polling', $poll['mode']);
        self::assertNull($poll['hub']);
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'Без хаба — жодної куки');
    }

    public function testRealtimeWithHubIssuesPersonalHttpOnlyCookie(): void
    {
        $secret = 'test-secret-that-is-long-enough-for-hs256!';
        self::getContainer()->set(MercurePublisher::class, new MercurePublisher(
            new MockHub('http://127.0.0.1:3100/.well-known/mercure', new StaticTokenProvider('t'), static fn (): string => 'id', new LcobucciFactory($secret, protocolVersion: ProtocolVersion::Legacy), 'https://plan.test/.well-known/mercure', 'mercureAuthorization', ProtocolVersion::Legacy),
            new NullLogger(),
            'http://127.0.0.1:3100/.well-known/mercure',
            'https://plan.test',
        ));
        $user = $this->person();
        $this->client->loginUser($user);

        $live = $this->json('GET', '/api/plan/realtime');
        self::assertSame('mercure', $live['mode']);
        self::assertSame('https://plan.test/.well-known/mercure', $live['hub']);
        self::assertSame('https://plan.test/u/' . $user->getId(), $live['topic']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $cookies = $this->client->getResponse()->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('mercureAuthorization', $cookies[0]->getName());
        self::assertTrue($cookies[0]->isHttpOnly(), 'JWT недоступний JS-у');
        self::assertSame('/.well-known/mercure', $cookies[0]->getPath());
        self::assertSame('strict', $cookies[0]->getSameSite());
        $claims = json_decode(base64_decode(strtr(explode('.', (string) $cookies[0]->getValue())[1], '-_', '+/')), true);
        self::assertSame(['https://plan.test/u/' . $user->getId()], $claims['mercure']['subscribe'], 'Підписка лише на власний топік');
        self::assertArrayNotHasKey('publish', array_filter($claims['mercure'], static fn ($v) => $v !== []), 'Браузер нічого не публікує');
        self::assertGreaterThan(time() + 11 * 3600, $claims['exp']);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function json(string $method, string $url, array $body = []): array
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: $body !== [] ? json_encode($body, JSON_THROW_ON_ERROR) : null);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
