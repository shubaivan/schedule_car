<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Tasks\Enum\WorkRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * «Звʼязок» (ТЗ §21): особисті й групові чати, бачать лише учасники, закріплення, архів,
 * непрочитані, відповіді, згадки, редагування з історією, видалення, галочки прочитання.
 * До погодження — лише люди з повним доступом.
 */
class ZvyazokApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        parent::tearDown();
    }

    public function testOnlyFullAccessUntilApprovalAndOnlyMembersSeeTheChat(): void
    {
        $ann = $this->user('Анна');
        $bob = $this->user('Борис');
        $outsider = $this->user('Сторонній');
        $plain = $this->user('Звичайний', full: false);

        $this->client->loginUser($plain);
        $this->call('GET', '/api/plan/zvyazok/chats');
        self::assertResponseStatusCodeSame(404, 'без повного доступу модуля немає');
        self::assertFalse($this->call('GET', '/api/me')['zvyazok']);

        $this->client->loginUser($ann);
        self::assertTrue($this->call('GET', '/api/me')['zvyazok']);
        $people = array_column($this->call('GET', '/api/plan/zvyazok/people')['items'], 'id');
        self::assertContains($bob->getId(), $people);
        self::assertNotContains($plain->getId(), $people, 'додати можна лише тих, кому модуль відкритий');
        $this->call('POST', '/api/plan/zvyazok/chats', ['kind' => 'direct', 'userId' => $plain->getId()]);
        self::assertResponseStatusCodeSame(422);

        $chat = $this->call('POST', '/api/plan/zvyazok/chats', ['kind' => 'direct', 'userId' => $bob->getId()]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Борис Тест', $chat['title']);
        $again = $this->call('POST', '/api/plan/zvyazok/chats', ['kind' => 'direct', 'userId' => $bob->getId()]);
        self::assertSame($chat['id'], $again['id'], 'один особистий чат на пару');

        $this->call('POST', '/api/plan/zvyazok/chats/' . $chat['id'] . '/messages', ['text' => 'Привіт']);
        self::assertResponseStatusCodeSame(201);

        $this->client->loginUser($outsider);
        foreach (['GET /chats/%d', 'GET /chats/%d/messages', 'POST /chats/%d/messages', 'POST /chats/%d/read'] as $call) {
            [$method, $path] = explode(' ', $call);
            $this->call($method, '/api/plan/zvyazok' . sprintf($path, $chat['id']), ['text' => 'я тут', 'messageId' => 1]);
            self::assertResponseStatusCodeSame(404, $call);
        }
        self::assertSame([], $this->call('GET', '/api/plan/zvyazok/chats')['items']);

        // Запис — лише JSON.
        $this->client->loginUser($ann);
        $this->client->request('POST', '/api/plan/zvyazok/chats/' . $chat['id'] . '/messages', ['text' => 'форма']);
        self::assertResponseStatusCodeSame(415);
    }

    public function testGroupUnreadReceiptsRepliesMentionsEditDelete(): void
    {
        $ann = $this->user('Анна');
        $bob = $this->user('Борис');
        $cid = $this->user('Віра');

        $this->client->loginUser($ann);
        $group = $this->call('POST', '/api/plan/zvyazok/chats', ['kind' => 'group', 'title' => 'Відвантаження', 'memberIds' => [$bob->getId(), $cid->getId()]]);
        self::assertTrue($group['owner']);
        self::assertCount(3, $group['members']);
        $url = '/api/plan/zvyazok/chats/' . $group['id'];

        $first = $this->call('POST', $url . '/messages', ['text' => 'Машина о 9:00', 'mentions' => [$bob->getId(), 999999]]);
        self::assertSame([$bob->getId()], $first['mentions'], 'згадати можна лише учасника');
        self::assertSame([0, 2], [$first['readBy'], $first['readOf']], '✓ — надіслано, ще ніхто не прочитав');

        $this->client->loginUser($bob);
        $row = $this->call('GET', '/api/plan/zvyazok/chats')['items'][0];
        self::assertSame(['Відвантаження', 1, 1], [$row['title'], $row['unread'], $row['mentions']]);
        self::assertSame(1, $this->call('GET', '/api/plan/zvyazok/unread')['unread']);
        $this->call('POST', $url . '/read', ['messageId' => $first['id']]);
        self::assertSame(0, $this->call('GET', '/api/plan/zvyazok/unread')['unread']);
        $reply = $this->call('POST', $url . '/messages', ['text' => 'Буде', 'replyTo' => $first['id']]);
        self::assertSame($first['id'], $reply['reply']['id']);
        self::assertSame('Машина о 9:00', $reply['reply']['text']);

        $this->client->loginUser($cid);
        $this->call('POST', $url . '/read', ['messageId' => 999999999]);
        $items = $this->call('GET', $url . '/messages')['items'];
        self::assertSame([2, 2], [$items[0]['readBy'], $items[0]['readOf']], '✓✓ — прочитали всі');
        self::assertSame([1, 2], [$items[1]['readBy'], $items[1]['readOf']], 'відповідь Бориса прочитала Віра, ще не Анна');

        // Чуже не редагують і не видаляють.
        $this->call('PATCH', '/api/plan/zvyazok/messages/' . $first['id'], ['text' => 'зламав']);
        self::assertResponseStatusCodeSame(403);
        $this->call('PATCH', $url, ['title' => 'Моя назва']);
        self::assertResponseStatusCodeSame(403, 'назву змінює власник');

        $this->client->loginUser($ann);
        $edited = $this->call('PATCH', '/api/plan/zvyazok/messages/' . $first['id'], ['text' => 'Машина о 10:00']);
        self::assertTrue($edited['edited']);
        self::assertSame([['text' => 'Машина о 9:00']], array_map(static fn ($h) => ['text' => $h['text']], $this->call('GET', '/api/plan/zvyazok/messages/' . $first['id'] . '/history')['items']));
        $this->call('DELETE', '/api/plan/zvyazok/messages/' . $first['id']);
        self::assertSame([], $this->call('GET', '/api/plan/zvyazok/messages/' . $first['id'] . '/history')['items'], 'видалене для всіх — і без попередніх версій');
        $items = $this->call('GET', $url . '/messages')['items'];
        self::assertTrue($items[0]['deleted']);
        self::assertSame('', $items[0]['text']);
        self::assertSame('Повідомлення видалено', $items[1]['reply']['text']);

        // Власник виходить — власником стає наступний; хто вийшов, чату не бачить.
        $this->call('POST', $url . '/leave');
        $this->call('GET', $url);
        self::assertResponseStatusCodeSame(404);
        $this->client->loginUser($bob);
        self::assertTrue($this->call('GET', $url)['owner']);

        // Повернули в чат — «прочитано» з моменту повернення, а не всі старі як нові.
        $this->call('POST', $url . '/messages', ['text' => 'Поки Анни немає']);
        $this->call('PATCH', $url, ['add' => [$ann->getId()]]);
        self::assertResponseIsSuccessful();
        $this->client->loginUser($ann);
        $row = array_first(array_filter($this->call('GET', '/api/plan/zvyazok/chats')['items'], static fn ($r) => $r['id'] === $group['id']));
        self::assertSame(0, $row['unread']);
    }

    public function testPinArchiveAndSearchInTheLeftColumn(): void
    {
        $ann = $this->user('Анна');
        $bob = $this->user('Борис');
        $cid = $this->user('Віра');
        $this->client->loginUser($ann);

        $a = $this->call('POST', '/api/plan/zvyazok/chats', ['kind' => 'group', 'title' => 'Бетон М300', 'memberIds' => [$bob->getId()]]);
        $b = $this->call('POST', '/api/plan/zvyazok/chats', ['kind' => 'direct', 'userId' => $cid->getId()]);
        self::assertSame([$b['id'], $a['id']], array_column($this->call('GET', '/api/plan/zvyazok/chats')['items'], 'id'), 'новіші вгорі');

        $this->call('POST', '/api/plan/zvyazok/chats/' . $a['id'] . '/flag', ['pinned' => true]);
        self::assertSame([$a['id'], $b['id']], array_column($this->call('GET', '/api/plan/zvyazok/chats')['items'], 'id'), 'закріплений — першим');

        $this->call('POST', '/api/plan/zvyazok/chats/' . $b['id'] . '/flag', ['archived' => true]);
        self::assertSame([$a['id']], array_column($this->call('GET', '/api/plan/zvyazok/chats')['items'], 'id'));
        self::assertSame([$b['id']], array_column($this->call('GET', '/api/plan/zvyazok/chats?archived=1')['items'], 'id'));

        // Нове повідомлення повертає чат з архіву.
        $this->client->loginUser($cid);
        $this->call('POST', '/api/plan/zvyazok/chats/' . $b['id'] . '/messages', ['text' => 'Де накладна?']);
        $this->client->loginUser($ann);
        self::assertContains($b['id'], array_column($this->call('GET', '/api/plan/zvyazok/chats')['items'], 'id'));

        self::assertSame([$a['id']], array_column($this->call('GET', '/api/plan/zvyazok/chats?q=' . urlencode('м300'))['items'], 'id'), 'за назвою');
        self::assertSame([$b['id']], array_column($this->call('GET', '/api/plan/zvyazok/chats?q=' . urlencode('Віра'))['items'], 'id'), 'за учасником');
        self::assertSame([$b['id']], array_column($this->call('GET', '/api/plan/zvyazok/chats?q=' . urlencode('накладна'))['items'], 'id'), 'за текстом');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $url, array $body = []): array
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: $method === 'GET' ? null : json_encode($body, JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function user(string $name, bool $full = true): TelegramUser
    {
        $user = new TelegramUser()
            ->setTelegramId('zv-' . uniqid('', true))
            ->setFirstName($name)
            ->setLastName('Тест')
            ->setWorkRole(WorkRole::Manager)
            ->setPlanFullAccess($full);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
