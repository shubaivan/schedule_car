<?php

namespace App\Tests\Tasks;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Tasks\Enum\WorkRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Стрічка «Плану»: Хроніка (події, +N, непрочитані, ★, сторінки), дії з
 * повідомленням (відповідь, правка, видалення, закріплення, ★) з правами,
 * «хто прочитав» і глобальний пошук, який не показує чужого.
 */
class TaskFeedTest extends WebTestCase
{
    private const PREFIX = 'feed-test-';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $ids = $conn->fetchFirstColumn('SELECT id FROM telegram_user WHERE telegram_id LIKE ?', [self::PREFIX . '%']);

        if ($ids !== []) {
            $list = implode(',', array_map('intval', $ids));
            $conn->executeStatement("DELETE FROM task WHERE creator_id IN ($list)");
            $conn->executeStatement("DELETE FROM telegram_user WHERE id IN ($list)");
        }

        parent::tearDown();
    }

    public function testChronicleIsAnEventInboxWithUnreadAndMarksReadOnOpen(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $worker = $this->user(WorkRole::Production, 'wrk');
        $outsider = $this->user(WorkRole::Production, 'out');

        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Балки Б-1', 'memberIds' => [$worker->getId()]]);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'Креслення в додатку']);

        $this->login($worker);
        $feed = $this->get('/api/tasks/chronicle?unread=1');
        $row = $this->row($feed, $task['id']);
        self::assertNotNull($row, 'Нова задача — у непрочитаних');
        self::assertSame(2, $row['unread'], 'Створення + коментар');
        self::assertSame('comment', $row['event']['type']);
        self::assertSame('Креслення в додатку', $row['event']['text']);
        self::assertSame($manager->getId(), $row['event']['author']['id']);
        self::assertGreaterThanOrEqual(1, $feed['counts']['tasks']);

        // Відкрив задачу — прочитано; «Нові повідомлення» знають, звідки читати.
        $detail = $this->get('/api/tasks/' . $task['id']);
        self::assertSame(0, $detail['readUpTo']);
        self::assertNull($this->row($this->get('/api/tasks/chronicle?unread=1'), $task['id']));
        $all = $this->row($this->get('/api/tasks/chronicle'), $task['id']);
        self::assertNotNull($all);
        self::assertSame(0, $all['unread']);

        // Закриття — подія «closed», задача закреслена (closed).
        $this->login($manager);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/status', ['to' => 'in_work']);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/status', ['to' => 'done']);
        $this->login($worker);
        $row = $this->row($this->get('/api/tasks/chronicle?unread=1'), $task['id']);
        self::assertSame('closed', $row['event']['type']);
        self::assertTrue($row['task']['closed']);
        self::assertSame(2, $row['unread']);

        // «Прочитати все».
        $counts = $this->send('POST', '/api/tasks/chronicle/read-all', []);
        self::assertSame(0, $counts['tasks']);

        // Директор бачить задачу, але в Хроніці — лише те, де він бере участь або ★.
        $director = $this->user(WorkRole::Director, 'dir');
        $this->login($director);
        self::assertNull($this->row($this->get('/api/tasks/chronicle'), $task['id']));
        $this->send('POST', '/api/tasks/' . $task['id'] . '/favorite', ['on' => true]);
        self::assertNotNull($this->row($this->get('/api/tasks/chronicle'), $task['id']));
        $this->client->request('GET', '/api/tasks/chronicle?cursor=' . urlencode("x' OR 1=1"));
        self::assertResponseStatusCodeSame(400);

        // Чужий нічого не бачить.
        $this->login($outsider);
        self::assertNull($this->row($this->get('/api/tasks/chronicle'), $task['id']));
        $this->send('POST', '/api/tasks/' . $task['id'] . '/read', []);
        self::assertResponseStatusCodeSame(403);
    }

    /** «+N» на картці дошки, у Хроніці й лічильник меню — одне джерело (TaskFeedRepository::UNREAD). */
    public function testBoardAndChronicleAgreeOnUnread(): void
    {
        $manager = $this->user(WorkRole::Manager, 'agm');
        $worker = $this->user(WorkRole::Production, 'agw');

        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Кільця КС узгодження', 'memberIds' => [$worker->getId()]]);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'Перше']);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/status', ['to' => 'in_work']);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'Друге']);

        $this->login($worker);
        $chronicle = $this->row($this->get('/api/tasks/chronicle'), $task['id']);
        $board = $this->get('/api/tasks/board');
        $card = null;
        foreach ($board as $column) {
            foreach ($column as $item) {
                if ($item['id'] === $task['id']) {
                    $card = $item;
                }
            }
        }

        self::assertNotNull($card);
        self::assertSame(4, $chronicle['unread'], 'створення + 2 коментарі + зміна статусу');
        self::assertSame($chronicle['unread'], $card['unread']);
        self::assertSame(1, $this->get('/api/tasks/chronicle/counts')['tasks']);

        // Директор бачить задачу на дошці, але не бере в ній участі — «+N» немає, як і в його Хроніці.
        $director = $this->user(WorkRole::Director, 'agd');
        $this->login($director);
        foreach ($this->get('/api/tasks/board') as $column) {
            foreach ($column as $item) {
                if ($item['id'] === $task['id']) {
                    self::assertSame(0, $item['unread']);
                }
            }
        }
        self::assertNull($this->row($this->get('/api/tasks/chronicle'), $task['id']));

        $this->login($worker);
        $this->get('/api/tasks/' . $task['id']);
        self::assertSame(0, $this->row($this->get('/api/tasks/chronicle'), $task['id'])['unread']);
        self::assertSame(0, $this->get('/api/tasks/chronicle/counts')['tasks']);
    }

    public function testChroniclePagesByCursorAndFiltersFavorites(): void
    {
        $manager = $this->user(WorkRole::Manager, 'pager');
        $outsider = $this->user(WorkRole::Accountant, 'out');
        $this->login($manager);

        $ids = [];
        for ($i = 1; $i <= 7; ++$i) {
            $ids[] = $this->send('POST', '/api/tasks', ['title' => 'Угода ' . $i])['id'];
        }

        $first = $this->get('/api/tasks/chronicle?limit=5');
        self::assertCount(5, $first['items']);
        self::assertNotNull($first['next']);
        $second = $this->get('/api/tasks/chronicle?limit=5&cursor=' . urlencode($first['next']));
        self::assertCount(2, $second['items']);
        self::assertNull($second['next']);
        $seen = array_merge(array_column(array_column($first['items'], 'task'), 'id'), array_column(array_column($second['items'], 'task'), 'id'));
        self::assertEqualsCanonicalizing($ids, $seen, 'Кожна задача — рівно один раз');

        $this->send('POST', '/api/tasks/' . $ids[2] . '/favorite', ['on' => true]);
        $fav = $this->get('/api/tasks/chronicle?favorites=1');
        self::assertSame([$ids[2]], array_column(array_column($fav['items'], 'task'), 'id'));
        self::assertTrue($this->get('/api/tasks/' . $ids[2])['favorite']);
        $this->send('POST', '/api/tasks/' . $ids[2] . '/favorite', ['on' => false]);
        self::assertSame([], $this->get('/api/tasks/chronicle?favorites=1')['items']);

        $this->login($outsider);
        $this->send('POST', '/api/tasks/' . $ids[0] . '/favorite', ['on' => true]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCommentActionsRespectAuthorship(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $worker = $this->user(WorkRole::Production, 'wrk');
        $admin = $this->user(WorkRole::Admin, 'adm');
        $outsider = $this->user(WorkRole::Production, 'out');

        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Плити ПК', 'memberIds' => [$worker->getId()]]);
        $other = $this->send('POST', '/api/tasks', ['title' => 'Інша задача', 'memberIds' => [$worker->getId()]]);
        $base = '/api/tasks/' . $task['id'] . '/messages';
        $original = $this->send('POST', $base, ['text' => 'Ціна 3580 грн']);
        self::assertTrue($original['can']['edit']);
        self::assertTrue($original['can']['delete']);
        $foreign = $this->send('POST', '/api/tasks/' . $other['id'] . '/messages', ['text' => 'Секрет іншої задачі']);

        // Відповідь із цитатою.
        $this->login($worker);
        $reply = $this->send('POST', $base, ['text' => 'Погоджено', 'replyTo' => $original['id']]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($original['id'], $reply['replyTo']['id']);
        self::assertSame('Ціна 3580 грн', $reply['replyTo']['text']);
        self::assertSame($manager->shortName(), $reply['replyTo']['author']);

        // Цитувати повідомлення з іншої задачі не можна.
        $this->send('POST', $base, ['text' => 'хак', 'replyTo' => $foreign['id']]);
        self::assertResponseStatusCodeSame(422);

        // Чуже не правлять і не видаляють.
        $detail = $this->get('/api/tasks/' . $task['id']);
        $seen = $this->find($detail['messages'], $original['id']);
        self::assertFalse($seen['can']['edit']);
        self::assertFalse($seen['can']['delete']);
        $this->send('PATCH', $base . '/' . $original['id'], ['text' => 'Ціна 1 грн']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('DELETE', $base . '/' . $original['id']);
        self::assertResponseStatusCodeSame(422);
        // Повідомлення з іншої задачі через цю URL — 404.
        $this->send('PATCH', $base . '/' . $foreign['id'], ['text' => 'x']);
        self::assertResponseStatusCodeSame(404);

        // Закріпити й ★ — може учасник; ★ особиста.
        $pinned = $this->send('POST', $base . '/' . $original['id'] . '/pin', ['on' => true]);
        self::assertNotNull($pinned['pinned']);
        $starred = $this->send('POST', $base . '/' . $original['id'] . '/star', ['on' => true]);
        self::assertTrue($starred['starred']);
        $pollFrom = $this->get($base . '?after=' . $reply['id'])['now'];

        // Автор правит своє — позначка «змінено», старий текст в історії.
        $this->login($manager);
        $edited = $this->send('PATCH', $base . '/' . $original['id'], ['text' => 'Ціна 3650 грн']);
        self::assertResponseIsSuccessful();
        self::assertSame('Ціна 3650 грн', $edited['text']);
        self::assertNotNull($edited['editedAt']);
        self::assertFalse($edited['starred'], '★ працівника не видно менеджеру');
        self::assertSame(['Ціна 3580 грн'], $this->em->getConnection()->fetchFirstColumn('SELECT old_text FROM task_message_edit WHERE message_id = ?', [$original['id']]));

        // Відкритий чат працівника підтягує правку опитуванням.
        $this->login($worker);
        $poll = $this->get($base . '?after=' . $reply['id'] . '&changedSince=' . urlencode($pollFrom));
        $changed = $this->find($poll['changed'], $original['id']);
        self::assertSame('Ціна 3650 грн', $changed['text']);
        self::assertTrue($changed['starred']);

        // Чужий не робить нічого.
        $this->login($outsider);
        $this->send('POST', $base . '/' . $original['id'] . '/pin', ['on' => false]);
        self::assertResponseStatusCodeSame(403);
        $this->send('PATCH', $base . '/' . $original['id'], ['text' => 'x']);
        self::assertResponseStatusCodeSame(403);

        // Своє видаляють (мʼяко), цитата у відповіді стає «видалено»; адмін видаляє будь-яке.
        $this->login($manager);
        $this->client->request('DELETE', $base . '/' . $original['id']);
        self::assertResponseIsSuccessful();
        $detail = $this->get('/api/tasks/' . $task['id']);
        self::assertTrue($this->find($detail['messages'], $original['id'])['deleted']);
        self::assertNull($this->find($detail['messages'], $original['id'])['pinned'], 'Видалене відкріплюється');
        self::assertTrue($this->find($detail['messages'], $reply['id'])['replyTo']['deleted']);
        self::assertSame('', $this->find($detail['messages'], $reply['id'])['replyTo']['text']);
        self::assertSame('Ціна 3650 грн', $this->em->getConnection()->fetchOne('SELECT text FROM task_message WHERE id = ?', [$original['id']]), 'У базі текст лишається для журналу');

        $this->login($admin);
        $this->client->request('DELETE', $base . '/' . $reply['id']);
        self::assertResponseIsSuccessful();
    }

    public function testReadReceiptsShowWhoReadWhat(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $worker = $this->user(WorkRole::Production, 'wrk');

        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Розкрій', 'memberIds' => [$worker->getId()]]);
        $message = $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'Лист 6 мм']);

        $reads = $this->get('/api/tasks/' . $task['id'])['reads'];
        self::assertNotContains($worker->getId(), array_column($reads, 'userId'));

        $this->login($worker);
        $this->get('/api/tasks/' . $task['id']);

        $this->login($manager);
        $reads = array_column($this->get('/api/tasks/' . $task['id'])['reads'], 'lastMessageId', 'userId');
        self::assertGreaterThanOrEqual($message['id'], $reads[$worker->getId()]);
        $poll = $this->get('/api/tasks/' . $task['id'] . '/messages?after=' . $message['id']);
        self::assertContains($worker->getId(), array_column($poll['reads'], 'userId'));
    }

    public function testSearchFindsByPartOfWordAndHidesForeignTasks(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $worker = $this->user(WorkRole::Production, 'wrk');
        $outsider = $this->user(WorkRole::Production, 'out');
        $director = $this->user(WorkRole::Director, 'dir');
        $token = 'жфк' . substr(md5(uniqid()), 0, 6);

        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', [
            'title' => 'Укриття ' . $token . ' на 50 осіб',
            'client' => 'ТОВ Будінвест',
            'memberIds' => [$worker->getId()],
        ]);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'Прорахунок укриття ' . $token . ' готовий, дивіться креслення']);
        $gone = $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'Чернетка ' . $token]);
        $this->client->request('DELETE', '/api/tasks/' . $task['id'] . '/messages/' . $gone['id']);

        $file = tempnam(sys_get_temp_dir(), 'srch');
        file_put_contents($file, 'креслення');
        $this->client->request('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => ''], ['files' => [new UploadedFile($file, 'Креслення-' . $token . '.txt', 'text/plain', null, true)]]);
        self::assertResponseStatusCodeSame(201);

        // Частина слова («жфк…» без кінця), регістр не важливий.
        $partial = mb_strtoupper(mb_substr($token, 0, 6));

        $this->login($worker);
        $found = $this->get('/api/tasks/search?q=' . urlencode($partial));
        self::assertSame(1, $found['counts']['tasks']);
        self::assertSame(1, $found['counts']['comments'], 'Видалений коментар не шукається');
        self::assertSame(1, $found['counts']['files']);
        self::assertSame($task['id'], $found['tasks'][0]['id']);
        self::assertContains(true, array_column($found['tasks'][0]['title'], 'm'), 'Збіг підсвічено');
        $marked = implode('', array_map(static fn ($p) => $p['m'] ? $p['t'] : '', $found['comments'][0]['snippet']));
        self::assertSame(mb_strtolower($partial), mb_strtolower($marked));
        self::assertSame('Креслення-' . $token . '.txt', $found['files'][0]['plainName']);

        // Кілька слів — кожне має знайтись; номер задачі.
        self::assertSame(1, $this->get('/api/tasks/search?tab=tasks&q=' . urlencode('будінв ' . $token))['counts']['tasks']);
        self::assertSame(0, $this->get('/api/tasks/search?tab=tasks&q=' . urlencode('бетон ' . $token))['counts']['tasks']);
        self::assertContains($task['id'], array_column($this->get('/api/tasks/search?tab=tasks&q=' . urlencode('№' . $task['id']))['tasks'], 'id'));

        // ★ — лише обране.
        self::assertSame(0, $this->get('/api/tasks/search?starred=1&q=' . urlencode($token))['counts']['tasks']);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/favorite', ['on' => true]);
        self::assertSame(1, $this->get('/api/tasks/search?starred=1&q=' . urlencode($token))['counts']['tasks']);

        // Чужий не знаходить нічого — ні задачі, ні коментаря, ні файлу.
        $this->login($outsider);
        $none = $this->get('/api/tasks/search?q=' . urlencode($token));
        self::assertSame(0, $none['counts']['tasks'] + $none['counts']['comments'] + $none['counts']['files']);
        self::assertSame([], $none['tasks']);

        // Директор бачить усе.
        $this->login($director);
        self::assertSame(1, $this->get('/api/tasks/search?q=' . urlencode($token))['counts']['comments']);

        // Люди.
        self::assertContains($worker->getId(), array_column($this->get('/api/tasks/search?tab=people&q=' . urlencode('wrk'))['people'], 'id'));

        // Порожній / короткий запит — нічого, невідома вкладка — 422.
        self::assertSame(0, $this->get('/api/tasks/search?q=a')['counts']['tasks']);
        $this->client->request('GET', '/api/tasks/search?q=abc&tab=secret');
        self::assertResponseStatusCodeSame(422);
    }

    /** @param array<string, mixed> $feed @return array<string, mixed>|null */
    private function row(array $feed, int $taskId): ?array
    {
        foreach ($feed['items'] as $item) {
            if ($item['task']['id'] === $taskId) {
                return $item;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $messages @return array<string, mixed> */
    private function find(array $messages, int $id): array
    {
        foreach ($messages as $message) {
            if ($message['id'] === $id) {
                return $message;
            }
        }

        self::fail('Повідомлення ' . $id . ' не знайдено');
    }

    private function user(WorkRole $role, string $name): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId(self::PREFIX . $name . '-' . uniqid())
            ->setFirstName(ucfirst($name))
            ->setLastName('Стрічка')
            ->setWorkRole($role)
            ->setEmail(self::PREFIX . $name . uniqid() . '@example.test');
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function login(TelegramUser $user): void
    {
        $this->client->loginUser($user);
    }

    /** @return array<string, mixed> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, array $body): array
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
