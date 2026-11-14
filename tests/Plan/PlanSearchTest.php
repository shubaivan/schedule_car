<?php

namespace App\Tests\Plan;

use App\Plan\Search\SearchProjector;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Enum\WorkRole;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Entity\WhCategory;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\CategoryScope;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Service\ActivityLog;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Розумний пошук «Плану» (S4): один індекс search_document на задачі, коментарі,
 * файли, заявки, склад і людей; видимість за ролями; фасети, що рахуються без
 * власного фільтра; «усі прецеденти людини» без слова; індекс живе за подіями
 * й перебудовується ідемпотентно.
 */
class PlanSearchTest extends WebTestCase
{
    use S4Fixtures;

    private const PREFIX = 's4-search-';

    public function testExecutorSeesOnlyOwnTasksAndTheirCommentsAndFiles(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $worker = $this->user(WorkRole::Production, 'wrk');
        $outsider = $this->user(WorkRole::Production, 'out');
        $director = $this->user(WorkRole::Director, 'dir');
        $token = 'ькп' . substr(md5(uniqid()), 0, 6);

        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Укриття ' . $token, 'client' => 'ТОВ Будінвест', 'memberIds' => [$worker->getId()]]);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'Прорахунок ' . $token . ' готовий']);
        $this->upload($task['id'], 'Креслення-' . $token . '.txt');

        // Частина слова, інший регістр — задача, коментар і файл.
        $this->login($worker);
        $found = $this->search(['q' => mb_strtoupper(mb_substr($token, 0, 6))]);
        self::assertSame(3, $found['total']);
        self::assertEquals(['task' => 1, 'comment' => 1, 'file' => 1], self::facet($found, 'type'));
        self::assertSame('task', $found['hits'][0]['docType'], 'Сама задача — вище за її коментар');
        self::assertContains(true, array_column($found['hits'][0]['title'], 'm'), 'Збіг підсвічено');

        // Чужий не бачить ні задачі, ні коментаря, ні файлу — і в лічильниках теж.
        $this->login($outsider);
        $none = $this->search(['q' => $token]);
        self::assertSame(0, $none['total']);
        self::assertSame([], $none['hits']);
        self::assertSame([], self::facet($none, 'type'));
        self::assertSame(0, $this->search(['author' => $manager->getId()])['total'], 'Порожній запит + фільтр теж не видає чужого');

        // Директор бачить усе.
        $this->login($director);
        self::assertSame(3, $this->search(['q' => $token])['total']);

        // Учасника прибрали — разом із задачею зникли її коментарі й файли.
        $this->login($manager);
        $this->send('PATCH', '/api/tasks/' . $task['id'], ['memberIds' => []]);
        $this->login($worker);
        self::assertSame(0, $this->search(['q' => $token])['total']);
    }

    public function testFacetsCountWithoutOwnFilterAndPrecedentsOfAPersonWithoutQuery(): void
    {
        $token = 'цмн' . substr(md5(uniqid()), 0, 6);
        $mgrX = $this->user(WorkRole::Manager, 'x');
        $mgrY = $this->user(WorkRole::Manager, 'y');
        $supplyManager = $this->user(WorkRole::Manager, 'sm', SupplyRole::Manager);

        $this->login($mgrX);
        $a = $this->supply('Цемент ' . $token . ' ПЦ-500', 'Цех 1');
        $b = $this->supply('Цемент ' . $token . ' ПЦ-400', 'Цех 2');
        $this->send('POST', '/api/tasks', ['title' => 'Розрахунок цементу ' . $token]);

        $this->login($mgrY);
        $this->supply('Пісок ' . $token, 'Цех 1');
        $this->send('POST', '/api/tasks', ['title' => 'Цемент ' . $token . ' для плитки']);

        // Менеджер постачання взяв заявку в роботу й відписався — він «виконавець/менеджер» заявки.
        $this->login($supplyManager);
        $this->send('POST', '/api/supply/requests/' . $a['id'] . '/status', ['to' => 'in_progress']);
        $this->send('POST', '/api/supply/requests/' . $a['id'] . '/comments', ['text' => 'Ціну на цемент ' . $token . ' уточню']);

        $this->login($mgrX);
        // «цемент» + тип=заявка + автор=X: дві заявки X.
        $r = $this->search(['q' => 'цемент ' . $token, 'type' => 'supply', 'author' => $mgrX->getId()]);
        self::assertSame(2, $r['total']);
        self::assertEqualsCanonicalizing([$a['id'], $b['id']], array_column(array_column($r['hits'], 'subject'), 'id'));
        // Фасет «тип» рахується без фільтра типу (але з автором): заявки X (2) і задача X (1).
        self::assertEquals(['task' => 1, 'supply' => 2], self::facet($r, 'type'));
        // Фасет «автор» — без фільтра автора (але з типом): лише X («Пісок» Y не містить «цемент»).
        self::assertEquals([(string) $mgrX->getId() => 2], self::facet($r, 'author', 'id'));
        self::assertEquals(['supply:new' => 1, 'supply:in_progress' => 1], self::facet($r, 'status'));
        self::assertSame(1, $this->search(['q' => $token, 'type' => 'supply', 'status' => 'supply:in_progress'])['total']);
        self::assertSame(1, $this->search(['q' => $token, 'type' => 'supply', 'client' => 'цех 2'])['total'], 'Контрагент/об\'єкт — за частиною');

        // «Усі прецеденти менеджера постачання» без слова: заявка, яку він веде, і його коментар.
        $all = $this->search(['person' => $supplyManager->getId(), 'q' => '']);
        $mine = array_values(array_filter($all['hits'], static fn ($h) => str_contains(implode('', array_column($h['title'], 't')), $token)));
        self::assertEqualsCanonicalizing(['supply', 'supply_comment'], array_column($mine, 'docType'));
        self::assertSame(1, $this->search(['manager' => $supplyManager->getId(), 'type' => 'supply', 'q' => $token])['total'], 'Менеджер заявки — хто її веде');
        self::assertSame(1, $this->search(['executor' => $supplyManager->getId(), 'type' => 'supply', 'q' => $token])['total']);

        // Кілька слів — кожне має знайтись; невідомий тип — нічого, а не «усе».
        self::assertSame(0, $this->search(['q' => 'бетон ' . $token])['total']);
        self::assertSame(0, $this->search(['q' => $token, 'type' => 'nonsense'])['total']);

        // Сторінки.
        $page1 = $this->search(['q' => $token, 'limit' => 2]);
        self::assertTrue($page1['more']);
        $page2 = $this->search(['q' => $token, 'limit' => 2, 'offset' => 2]);
        self::assertEmpty(array_intersect(array_column($page1['hits'], 'id'), array_column($page2['hits'], 'id')));
    }

    public function testWarehouseIsVisibleOnlyWithWarehouseAccess(): void
    {
        $token = 'скл' . substr(md5(uniqid()), 0, 6);
        $worker = $this->user(WorkRole::Production, 'wh-wrk');
        $keeper = $this->user(WorkRole::Manager, 'wh-mgr', SupplyRole::Manager);

        $category = $this->em->getRepository(WhCategory::class)->findOneBy(['scope' => CategoryScope::Item]) ?? (new WhCategory())->setScope(CategoryScope::Item)->setName('Тест ' . $token)->setPrefix('ТТ');
        $this->em->persist($category);
        $item = (new WhItem())->setInventoryNumber('S4-' . $token)->setName('Перфоратор ' . $token)->setCategory($category)->setTracking(Tracking::Unit)->setUnit('шт');
        $this->em->persist($item);
        $this->em->flush();
        // Так само, як картка зі складу: запис у журнал → подія → індекс.
        self::getContainer()->get(ActivityLog::class)->record(ActivityAction::Create, $item, $keeper, WhActivity::CHANNEL_ADMIN);

        try {
            $this->login($keeper);
            $found = $this->search(['q' => $token]);
            self::assertSame(1, $found['total']);
            self::assertSame('wh_item', $found['hits'][0]['docType']);
            self::assertEquals(['warehouse' => 1], self::facet($found, 'type'));

            $this->login($worker);
            self::assertSame(0, $this->search(['q' => $token])['total']);
            self::assertNotContains('warehouse', array_column($this->get('/api/plan/search/meta')['types'], 'key'));
        } finally {
            $conn = $this->em->getConnection();
            $conn->executeStatement("DELETE FROM search_document WHERE subject_type = 'wh_item' AND subject_id = ?", [$item->getId()]);
            $conn->executeStatement("DELETE FROM plan_subject_activity WHERE subject_type = 'wh_item' AND subject_id = ?", [$item->getId()]);
            $conn->executeStatement('DELETE FROM wh_activity WHERE subject_id = ? AND subject_type = ?', [$item->getId(), 'item']);
            $conn->executeStatement('DELETE FROM wh_item WHERE id = ?', [$item->getId()]);
        }
    }

    public function testIndexFollowsCommentEditDeleteStatusAndFiles(): void
    {
        $manager = $this->user(WorkRole::Manager, 'ev-mgr');
        $token = 'пдя' . substr(md5(uniqid()), 0, 6);

        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Балки ' . $token]);
        $msg = $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'Перше ' . $token . ' слово']);
        self::assertSame(1, $this->search(['q' => 'перше ' . $token])['total']);

        $this->send('PATCH', '/api/tasks/' . $task['id'] . '/messages/' . $msg['id'], ['text' => 'Виправлене ' . $token]);
        self::assertSame(0, $this->search(['q' => 'перше ' . $token])['total'], 'Стара редакція не шукається');
        self::assertSame(1, $this->search(['q' => 'виправлене ' . $token])['total']);

        $this->client->request('DELETE', '/api/tasks/' . $task['id'] . '/messages/' . $msg['id']);
        self::assertSame(0, $this->search(['q' => 'виправлене ' . $token])['total'], 'Видалений коментар зник з індексу');

        $this->send('POST', '/api/tasks/' . $task['id'] . '/status', ['to' => 'in_work']);
        $r = $this->search(['q' => $token, 'status' => 'task:in_work']);
        self::assertSame(1, $r['total'], 'Статус задачі оновився в індексі');
        self::assertSame('В роботі', $r['hits'][0]['statusLabel']);

        $this->upload($task['id'], 'Кошторис-' . $token . '.txt');
        $files = $this->search(['q' => 'кошторис-' . $token, 'type' => 'file']);
        self::assertSame(1, $files['total']);
        self::assertStringContainsString('?f=', $files['hits'][0]['url']);

        // Заявка: коментар і зміна статусу — теж у індексі.
        $request = $this->supply('Фанера ' . $token, 'Цех 3');
        $this->send('POST', '/api/supply/requests/' . $request['id'] . '/comments', ['text' => 'Коментар ' . $token . ' до фанери']);
        self::assertSame(1, $this->search(['q' => 'коментар ' . $token, 'type' => 'comment'])['total']);
    }

    public function testReindexIsIdempotentAndRepairsTheIndex(): void
    {
        $manager = $this->user(WorkRole::Manager, 'ri-mgr');
        $token = 'рнд' . substr(md5(uniqid()), 0, 6);
        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Ригелі ' . $token]);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'Коментар ' . $token]);

        /** @var SearchProjector $projector */
        $projector = self::getContainer()->get(SearchProjector::class);
        $projector->reindex();
        $second = $projector->reindex();

        foreach ($second as $type => $r) {
            self::assertSame(['upserted' => 0, 'deleted' => 0], $r, 'Повтор нічого не змінює: ' . $type);
        }

        // Розійшлося з джерелом (зіпсували рядок, видалили рядок, лишився сирота) — reindex вирівнює.
        $conn = $this->em->getConnection();
        $conn->executeStatement("UPDATE search_document SET title = 'зіпсовано', search_text = 'зіпсовано' WHERE doc_type = 'task' AND ref_id = ?", [$task['id']]);
        $conn->executeStatement("DELETE FROM search_document WHERE doc_type = 'comment' AND subject_id = ?", [$task['id']]);
        $conn->executeStatement("INSERT INTO search_document (id, doc_type, subject_type, subject_id, ref_id, created_at, updated_at, visible_all) VALUES (nextval('search_document_id_seq'), 'comment', 'task', ?, 2000000, LOCALTIMESTAMP(0), LOCALTIMESTAMP(0), true)", [$task['id']]);

        $fixed = $projector->reindex(['task', 'comment']);
        self::assertSame(1, $fixed['task']['upserted']);
        self::assertSame(1, $fixed['comment']['upserted']);
        self::assertSame(1, $fixed['comment']['deleted']);
        self::assertSame(2, $this->search(['q' => $token])['total']);

        // Синтетика бенчмарка не видаляється бекфілом і не видна в пошуку.
        $conn->executeStatement("INSERT INTO search_document (id, doc_type, subject_type, subject_id, ref_id, title, search_text, created_at, updated_at, visible_all) VALUES (nextval('search_document_id_seq'), 'task', 'task', 1, ?, ?, ?, LOCALTIMESTAMP(0), LOCALTIMESTAMP(0), true)", [SearchProjector::BENCH_REF_BASE + 7, $token, $token]);

        try {
            self::assertSame(0, $projector->reindex(['task'])['task']['deleted']);
            self::assertSame(2, $this->search(['q' => $token])['total']);
        } finally {
            $conn->executeStatement('DELETE FROM search_document WHERE ref_id = ?', [SearchProjector::BENCH_REF_BASE + 7]);
        }

        // Команда: пробний запуск нічого не міняє, звичайний — успішний.
        $this->client->getKernel()->boot();
        $app = new \Symfony\Bundle\FrameworkBundle\Console\Application($this->client->getKernel());
        $tester = new \Symfony\Component\Console\Tester\CommandTester($app->find('plan:search:reindex'));
        self::assertSame(0, $tester->execute(['--dry-run' => true, '--type' => ['task']]));
        self::assertStringContainsString('Пробний запуск', $tester->getDisplay());
        self::assertSame(2, $tester->execute(['--type' => ['nope']]));
    }

    public function testSavedSearchesBelongToTheirOwner(): void
    {
        $me = $this->user(WorkRole::Manager, 'sv-me');
        $other = $this->user(WorkRole::Manager, 'sv-other');

        $this->login($me);
        $saved = $this->send('POST', '/api/plan/search/saved', ['name' => 'Цемент X', 'query' => '?q=цемент&type=supply&author=5&evil=1']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('q=%D1%86%D0%B5%D0%BC%D0%B5%D0%BD%D1%82&type=supply&author=5', $saved['query'], 'Лише параметри пошуку');
        self::assertSame(['Цемент X'], array_column($this->get('/api/plan/search/saved'), 'name'));

        $this->send('POST', '/api/plan/search/saved', ['name' => 'Порожній', 'query' => 'evil=1']);
        self::assertResponseStatusCodeSame(422);

        $this->login($other);
        self::assertSame([], $this->get('/api/plan/search/saved'));
        $this->client->request('DELETE', '/api/plan/search/saved/' . $saved['id']);
        self::assertResponseStatusCodeSame(404);

        $this->login($me);
        $this->client->request('DELETE', '/api/plan/search/saved/' . $saved['id']);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json());
    }

    public function testPeopleAreSearchableAndFollowArchive(): void
    {
        $token = 'Людм' . substr(md5(uniqid()), 0, 5);
        $person = $this->user(WorkRole::Accountant, mb_strtolower($token));
        $viewer = $this->user(WorkRole::Production, 'ppl-viewer');
        $person->setPosition('Головний ' . $token);
        $this->em->flush();

        $this->login($viewer);
        $found = $this->search(['q' => $token, 'type' => 'person']);
        self::assertSame(1, $found['total']);
        self::assertSame($person->getId(), $found['hits'][0]['subject']['id']);

        $person->archive();
        $this->em->flush();
        self::assertSame(0, $this->search(['q' => $token, 'type' => 'person'])['total'], 'Архівний — не в пошуку');
    }

    private function upload(int $taskId, string $name): void
    {
        $file = tempnam(sys_get_temp_dir(), 's4');
        file_put_contents($file, 'вміст');
        $this->client->request('POST', '/api/tasks/' . $taskId . '/messages', ['text' => ''], ['files' => [new UploadedFile($file, $name, 'text/plain', null, true)]]);
        self::assertResponseStatusCodeSame(201);
    }

    /** @return array<string, mixed> */
    private function supply(string $item, string $site): array
    {
        $created = $this->send('POST', '/api/supply/requests', ['item' => $item, 'quantity' => '5', 'unit' => 'ton', 'site' => $site]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $created;
    }
}
