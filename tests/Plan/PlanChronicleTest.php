<?php

namespace App\Tests\Plan;

use App\Plan\Chronicle\PlanChronicle;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Enum\WorkRole;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Хроніка v2 (S4): задачі + заявки (+ склад) з журналу plan_event; директор
 * бачить усе; фільтри за модулем і типом події; «+N» задач — те саме правило,
 * що й стара Хроніка/дошка; «+N» заявок — від чужих новин після прочитаного.
 */
class PlanChronicleTest extends WebTestCase
{
    use S4Fixtures;

    private const PREFIX = 's4-chron-';

    public function testDirectorSeesEverythingWorkerOnlyOwn(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $worker = $this->user(WorkRole::Production, 'wrk');
        $outsider = $this->user(WorkRole::Production, 'out');
        $director = $this->user(WorkRole::Director, 'dir');

        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Задача Хроніки', 'memberIds' => [$worker->getId()]]);
        $this->login($worker);
        $request = $this->send('POST', '/api/supply/requests', ['item' => 'Арматура', 'quantity' => '2', 'unit' => 'ton']);
        self::assertResponseStatusCodeSame(201);

        $this->login($director);
        $keys = $this->keys($this->get('/api/plan/chronicle', ['unread' => 0, 'limit' => 100]));
        self::assertContains('task-' . $task['id'], $keys, 'Директор бачить чужу задачу');
        self::assertContains('supply-' . $request['id'], $keys, 'Директор бачить заявки');

        $this->login($worker);
        $keys = $this->keys($this->get('/api/plan/chronicle', ['unread' => 0, 'limit' => 100]));
        self::assertContains('task-' . $task['id'], $keys);
        self::assertContains('supply-' . $request['id'], $keys, 'Своя заявка');

        $this->login($outsider);
        $keys = $this->keys($this->get('/api/plan/chronicle', ['unread' => 0, 'limit' => 100]));
        self::assertNotContains('task-' . $task['id'], $keys);
        self::assertNotContains('supply-' . $request['id'], $keys, 'Чужа заявка робітника не в його Хроніці');
        self::assertNotContains('warehouse', array_column($this->get('/api/plan/chronicle')['modules'], 'key'));

        // Фільтри: модуль і тип події.
        $this->login($director);
        $onlySupply = $this->get('/api/plan/chronicle', ['unread' => 0, 'module' => 'supply', 'limit' => 100]);
        self::assertSame(['supply'], array_values(array_unique(array_column($onlySupply['items'], 'module'))));
        $this->login($manager);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'Коментар у Хроніку']);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/status', ['to' => 'in_work']);
        $this->login($director);
        $comments = $this->get('/api/plan/chronicle', ['unread' => 0, 'event' => 'comment', 'limit' => 100]);
        $row = $this->row($comments, 'task-' . $task['id']);
        self::assertNotNull($row, 'Задача з коментарем — у фільтрі «Коментарі», хоч остання подія — статус');
        self::assertSame('comment', $row['event']['group']);
        self::assertSame('Коментар у Хроніку', $row['event']['text']);
        $latest = $this->row($this->get('/api/plan/chronicle', ['unread' => 0, 'limit' => 100]), 'task-' . $task['id']);
        self::assertSame('status', $latest['event']['group']);
        self::assertStringContainsString('→', $latest['event']['text']);

        // Невідомий модуль/тип — нічого, а не все.
        self::assertSame([], $this->get('/api/plan/chronicle', ['unread' => 0, 'module' => 'nope'])['items']);

        // Видалений коментар не «світиться» в Хроніці.
        $this->login($manager);
        $secret = $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'пароль 12345']);
        $this->client->request('DELETE', '/api/tasks/' . $task['id'] . '/messages/' . $secret['id']);
        $this->login($director);
        $row = $this->row($this->get('/api/plan/chronicle', ['unread' => 0, 'event' => 'comment', 'limit' => 100]), 'task-' . $task['id']);
        self::assertStringNotContainsString('12345', $row['event']['text']);
        self::assertSame('Повідомлення видалено', $row['event']['text']);
    }

    public function testTaskPlusNMatchesOldChronicleAndSupplyPlusNFollowsReads(): void
    {
        $manager = $this->user(WorkRole::Manager, 'n-mgr');
        $worker = $this->user(WorkRole::Production, 'n-wrk');
        $supplier = $this->user(WorkRole::Manager, 'n-sup', SupplyRole::Manager);

        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Плюс N', 'memberIds' => [$worker->getId()]]);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'раз']);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'два']);

        // «+N» задач — однакові в новій і старій Хроніці.
        $this->login($worker);
        $old = [];

        foreach ($this->get('/api/tasks/chronicle', ['limit' => 100])['items'] as $item) {
            $old['task-' . $item['task']['id']] = $item['unread'];
        }

        $new = [];

        foreach ($this->get('/api/plan/chronicle', ['unread' => 0, 'module' => 'task', 'limit' => 100])['items'] as $item) {
            $new[$item['key']] = $item['unread'];
        }

        self::assertSame(3, $new['task-' . $task['id']], 'Створення + два коментарі');
        self::assertEquals($old, array_intersect_key($new, $old));

        // Заявка робітника: менеджер постачання відповів — у робітника +2.
        $request = $this->send('POST', '/api/supply/requests', ['item' => 'Цвяхи', 'quantity' => '3', 'unit' => 'kg']);
        $this->login($supplier);
        $this->send('POST', '/api/supply/requests/' . $request['id'] . '/status', ['to' => 'in_progress']);
        $this->send('POST', '/api/supply/requests/' . $request['id'] . '/comments', ['text' => 'Купую']);

        $this->login($worker);
        $page = $this->get('/api/plan/chronicle', ['unread' => 1, 'limit' => 100]);
        $row = $this->row($page, 'supply-' . $request['id']);
        self::assertNotNull($row);
        self::assertSame(2, $row['unread'], 'Статус + коментар від менеджера; власне створення не рахується');
        self::assertSame(1, $page['counts']['byModule']['supply'], 'Одна заявка з новим');
        self::assertSame(count(array_filter($page['items'], static fn ($i) => $i['unread'] > 0)), $page['counts']['tasks'], 'Бейдж = рядки з новим');

        // Прочитав — нуль; нова чужа новина — знову +1.
        $counts = $this->send('POST', '/api/plan/chronicle/supply-' . $request['id'] . '/read');
        self::assertArrayNotHasKey('supply', array_filter($counts['byModule']));
        self::assertNull($this->row($this->get('/api/plan/chronicle', ['unread' => 1, 'limit' => 100]), 'supply-' . $request['id']));
        $this->login($supplier);
        $this->send('POST', '/api/supply/requests/' . $request['id'] . '/comments', ['text' => 'Привезуть завтра']);
        $this->login($worker);
        self::assertSame(1, $this->row($this->get('/api/plan/chronicle', ['unread' => 1, 'limit' => 100]), 'supply-' . $request['id'])['unread']);

        // Менеджер постачання веде всі заявки — у нього +N на заявці робітника є.
        $this->send('POST', '/api/supply/requests/' . $request['id'] . '/comments', ['text' => 'Дякую']);
        $this->login($supplier);
        self::assertSame(1, $this->row($this->get('/api/plan/chronicle', ['unread' => 1, 'limit' => 100]), 'supply-' . $request['id'])['unread']);

        // «Прочитати все» — і задачі, і заявки.
        $this->login($worker);
        $after = $this->send('POST', '/api/plan/chronicle/read-all');
        self::assertSame(0, $after['tasks']);
        self::assertSame([], $this->get('/api/plan/chronicle', ['unread' => 1])['items']);

        // Чуже не позначиш: заявку бачать усі, але задачу — ні.
        $outsider = $this->user(WorkRole::Production, 'n-out');
        $this->login($outsider);
        $this->client->request('POST', '/api/plan/chronicle/task-' . $task['id'] . '/read');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/api/plan/chronicle/lead-1/read');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRebuildRestoresRowsAndCursorPages(): void
    {
        $manager = $this->user(WorkRole::Manager, 'rb-mgr');
        $this->login($manager);
        $ids = [];

        for ($i = 0; $i < 4; ++$i) {
            $ids[] = $this->send('POST', '/api/tasks', ['title' => 'Сторінка ' . $i])['id'];
        }

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM plan_subject_activity WHERE subject_type = ? AND subject_id IN (' . implode(',', $ids) . ')', ['task']);

        /** @var PlanChronicle $chronicle */
        $chronicle = self::getContainer()->get(PlanChronicle::class);
        self::assertGreaterThanOrEqual(4, $chronicle->rebuild());
        self::assertSame(0, $chronicle->rebuild(), 'Повтор нічого не змінює');

        // Перший запуск на стенді: стара історія заявок — прочитана, «+N» не вибухає.
        $request = $this->send('POST', '/api/supply/requests', ['item' => 'Історія', 'quantity' => '1', 'unit' => 'piece']);
        $reader = $this->user(WorkRole::Manager, 'rb-sup', SupplyRole::Manager);
        $this->login($reader);
        self::assertGreaterThan(0, $this->row($this->get('/api/plan/chronicle', ['unread' => 1, 'limit' => 100]), 'supply-' . $request['id'])['unread'] ?? 0);
        self::assertGreaterThan(0, $chronicle->markHistoryRead());
        self::assertNull($this->row($this->get('/api/plan/chronicle', ['unread' => 1, 'limit' => 100]), 'supply-' . $request['id']));
        $this->login($manager);

        $seen = [];
        $cursor = null;

        do {
            $page = $this->get('/api/plan/chronicle', array_filter(['unread' => '0', 'module' => 'task', 'limit' => 5, 'cursor' => $cursor], static fn ($v) => $v !== null));
            array_push($seen, ...$this->keys($page));
            $cursor = $page['next'];
        } while ($cursor !== null);

        self::assertSame(count($seen), count(array_unique($seen)), 'Сторінки не повторюються');

        foreach ($ids as $id) {
            self::assertContains('task-' . $id, $seen);
        }

        $this->client->request('GET', '/api/plan/chronicle?cursor=bad');
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<string>
     */
    private function keys(array $page): array
    {
        return array_column($page['items'], 'key');
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>|null
     */
    private function row(array $page, string $key): ?array
    {
        foreach ($page['items'] as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return null;
    }
}
