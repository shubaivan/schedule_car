<?php

namespace App\Tests\Warehouse;

use App\Entity\TelegramUser;
use App\Plan\Subject\Provider\WarehouseSubjects;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Repository\TelegramUserRepository;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Entity\Task;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\TasksSection;
use App\Warehouse\Api\ItemMentions;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\MovementType;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Service\ActivityLog;
use App\Warehouse\Service\RecordMovement;
use App\Warehouse\Service\WarehouseStock;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * JSON API складу для «Плану»: права за ролями, рух тим самим RecordMovement
 * (залишки сходяться з WarehouseStock — тим, що показує Twig-склад), рівно одна
 * подія на дію, перегляд картки — не частіше, ніж раз на кілька хвилин.
 */
class WarehouseApiTest extends WebTestCase
{
    use WarehouseFixtures;

    private KernelBrowser $browser;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        // Як фронт «Плану»: кожен запис несе X-Requested-With (WarehouseGate — замість CSRF).
        $this->browser->setServerParameter('HTTP_X_REQUESTED_WITH', 'XMLHttpRequest');
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
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

    public function testClosedWithoutLogin(): void
    {
        $this->browser->request('GET', '/api/warehouse/items');

        self::assertContains($this->browser->getResponse()->getStatusCode(), [302, 401]);
    }

    /** @return iterable<string, array{SupplyRole, bool}> */
    public static function roles(): iterable
    {
        yield 'робітник' => [SupplyRole::Worker, false];
        yield 'менеджер' => [SupplyRole::Manager, true];
        yield 'директор' => [SupplyRole::Director, true];
        yield 'адмін' => [SupplyRole::Admin, true];
    }

    #[DataProvider('roles')]
    public function testOnlyThoseWhoRunTheWarehouseGetIn(SupplyRole $role, bool $allowed): void
    {
        $item = $this->item();
        $this->browser->loginUser($this->person($role));

        foreach (['/api/warehouse/meta', '/api/warehouse/items', '/api/warehouse/items/' . $item->getId(), '/api/warehouse/items/' . $item->getId() . '/feed', '/api/warehouse/movements', '/api/warehouse/journal'] as $url) {
            $this->browser->request('GET', $url);
            self::assertSame($allowed ? 200 : 403, $this->browser->getResponse()->getStatusCode(), $url);
        }

        $this->postJson('/api/warehouse/movements', ['type' => 'receipt', 'occurredAt' => '2026-11-15', 'toId' => $this->warehouse()->getId(), 'lines' => [['itemId' => $item->getId(), 'quantity' => 1]]]);
        self::assertSame($allowed ? 201 : 403, $this->browser->getResponse()->getStatusCode());
    }

    public function testWriteWithoutPlanHeaderIsRefused(): void
    {
        $item = $this->item();
        $this->browser->loginUser($this->person());
        $this->browser->setServerParameter('HTTP_X_REQUESTED_WITH', '');

        // Звичайна HTML-форма з чужого сайту: multipart без заголовка «Плану».
        $this->browser->request('POST', '/api/warehouse/movements', ['data' => json_encode(['type' => 'receipt', 'occurredAt' => '2026-11-15', 'toId' => $this->warehouse()->getId(), 'lines' => [['itemId' => $item->getId(), 'quantity' => 1]]])]);
        self::assertResponseStatusCodeSame(403);

        // Читати можна і без нього.
        $this->browser->request('GET', '/api/warehouse/items');
        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{WorkRole}> */
    public static function readers(): iterable
    {
        yield 'менеджер з продажу' => [WorkRole::Manager];
        yield 'бухгалтер' => [WorkRole::Accountant];
    }

    /** Ревʼю UX №6: продажі й бухгалтерія бачать склад (позиції, залишки, рухи), але нічого не записують. */
    #[DataProvider('readers')]
    public function testSalesAndAccountingReadTheWarehouseButCannotWrite(WorkRole $role): void
    {
        $item = $this->item();
        $reader = $this->person(SupplyRole::Worker)->setWorkRole($role);
        $this->em->flush();
        $this->browser->loginUser($reader);

        foreach (['/api/warehouse/meta', '/api/warehouse/items', '/api/warehouse/items/' . $item->getId(), '/api/warehouse/items/' . $item->getId() . '/feed', '/api/warehouse/movements', '/api/warehouse/journal'] as $url) {
            $this->browser->request('GET', $url);
            self::assertSame(200, $this->browser->getResponse()->getStatusCode(), $url);
        }

        $this->browser->request('GET', '/api/warehouse/meta');
        self::assertFalse($this->body()['can']['edit'], 'форми запису фронт не показує');

        $before = $this->events();
        $this->postJson('/api/warehouse/movements', ['type' => 'receipt', 'occurredAt' => '2026-11-15', 'toId' => $this->warehouse()->getId(), 'lines' => [['itemId' => $item->getId(), 'quantity' => 1]]]);
        self::assertResponseStatusCodeSame(403);
        $this->postJson('/api/warehouse/items', ['name' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('PUT', '/api/warehouse/items/' . $item->getId(), server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"x"}');
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->events($before), 'жодної події запису');

        $this->browser->request('GET', '/api/me');
        $me = $this->body();
        self::assertTrue($me['capabilities']['warehouse.view']);
        self::assertFalse($me['capabilities']['warehouse.edit']);
        self::assertTrue($me['warehouseEnabled']);
        self::assertFalse($me['warehouse'], 'Twig-склад /sklad і меню бота — як і були, лише тим, хто веде склад');

        // Картку відкрити можна (Хроніка, поділитися файлом), а сповіщення про рухи — лише тим, хто веде склад.
        $subjects = new WarehouseSubjects($this->em, self::getContainer()->get(TelegramUserRepository::class), self::getContainer()->get(UrlGeneratorInterface::class), new TasksSection(true, 'tasks'));
        $ref = SubjectRef::of(SubjectType::WhItem, (int) $item->getId());
        self::assertTrue($subjects->visibleTo($ref, $reader));
        self::assertNotContains((int) $reader->getId(), $subjects->audience($ref));
    }

    public function testArchivedManagerIsLockedOut(): void
    {
        $manager = $this->person();
        $manager->archive(null);
        $this->em->flush();
        $this->browser->loginUser($manager);

        $this->browser->request('GET', '/api/warehouse/items');

        self::assertNotSame(200, $this->browser->getResponse()->getStatusCode());
    }

    public function testMovementFromPlanMovesStockLikeTwigAndWritesOneEvent(): void
    {
        $manager = $this->person();
        $warehouse = $this->warehouse();
        $client = $this->client();
        $site = $this->site($client);
        $rings = $this->item(Tracking::Bulk, 'Кільце КС 10-9', '15.00');
        $form = $this->item(Tracking::Unit, 'Форма КС 10-9');
        $this->browser->loginUser($manager);

        $this->postJson('/api/warehouse/movements', ['type' => 'receipt', 'occurredAt' => '2026-11-01', 'toId' => $warehouse->getId(), 'documentNumber' => 'ПН-1', 'lines' => [['itemId' => $rings->getId(), 'quantity' => 40], ['itemId' => $form->getId(), 'quantity' => 1]]]);
        self::assertResponseStatusCodeSame(201);

        $events = $this->events();
        $activity = $this->activityCount();
        $this->postJson('/api/warehouse/movements', ['type' => 'shipment', 'occurredAt' => '2026-11-03', 'fromId' => $warehouse->getId(), 'toId' => $site->getId(), 'documentNumber' => 'ВН-7', 'lines' => [['itemId' => $rings->getId(), 'quantity' => 15], ['itemId' => $form->getId(), 'quantity' => 1]]]);
        self::assertResponseStatusCodeSame(201);
        $shipment = $this->body();

        self::assertSame('Відвантаження №ВН-7 від 03.11.2026', $shipment['title']);
        self::assertCount(2, $shipment['items']);
        self::assertSame(['stock_moved'], array_column($this->events($events), 'type'), 'одна дія — одна подія «Плану»');
        self::assertSame(3, $this->activityCount() - $activity, 'журнал складу як у Twig: рух + кожна позиція');

        $stock = self::getContainer()->get(WarehouseStock::class);
        $this->em->clear();
        $rings = $this->em->find(WhItem::class, $rings->getId());
        self::assertSame(25, $stock->balanceAt($rings, $this->em->find($warehouse::class, $warehouse->getId())));
        self::assertSame(15, $stock->balanceAt($rings, $this->em->find($site::class, $site->getId())));

        // Список «Плану» показує ті самі залишки, що WarehouseStock (а отже й /sklad).
        $this->browser->request('GET', '/api/warehouse/items?q=' . urlencode($rings->getInventoryNumber()));
        $row = $this->body()['items'][0];
        self::assertSame($stock->total($rings), $row['total']);
        self::assertSame(40, $row['total']);
        self::assertSame([25, 15], array_column($row['places'], 'quantity'), 'склад першим, далі об\'єкти');

        $this->browser->request('GET', '/api/warehouse/items?place=' . $site->getId());
        self::assertEqualsCanonicalizing([$rings->getId(), $form->getId()], array_column($this->body()['items'], 'id'));

        // Ставка оренди підставилась з картки — як у Twig-формі.
        $line = $this->em->find(WhMovement::class, $shipment['id'])?->getLines()->first();
        self::assertSame('15.00', $line ? $line->getRentalRate() : null);
    }

    public function testRefusedMovementChangesNothing(): void
    {
        $manager = $this->person();
        $warehouse = $this->warehouse();
        $site = $this->site($this->client());
        $rings = $this->item(Tracking::Bulk);
        self::getContainer()->get(RecordMovement::class)(MovementType::Receipt, new DateTime('2026-11-01'), null, $warehouse, [['item' => $rings, 'quantity' => 5]], $manager);
        $this->browser->loginUser($manager);
        $events = $this->events();
        $movements = $this->em->getRepository(WhMovement::class)->count([]);

        $this->postJson('/api/warehouse/movements', ['type' => 'shipment', 'occurredAt' => '2026-11-02', 'fromId' => $warehouse->getId(), 'toId' => $site->getId(), 'lines' => [['itemId' => $rings->getId(), 'quantity' => 6]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('є 5', $this->body()['error']);
        self::assertSame([], $this->events($events));
        self::assertSame($movements, $this->em->getRepository(WhMovement::class)->count([]));
    }

    public function testNewSiteAndWaybillInTheSameSend(): void
    {
        $manager = $this->person();
        $warehouse = $this->warehouse();
        $item = $this->item(Tracking::Bulk);
        self::getContainer()->get(RecordMovement::class)(MovementType::Receipt, new DateTime('2026-11-01'), null, $warehouse, [['item' => $item, 'quantity' => 5]], $manager);
        $this->browser->loginUser($manager);

        $tmp = tempnam(sys_get_temp_dir(), 'wh');
        file_put_contents($tmp, '%PDF-1.4 накладна');
        $this->browser->request('POST', '/api/warehouse/movements', [
            'data' => json_encode(['type' => 'shipment', 'occurredAt' => '2026-11-02', 'fromId' => $warehouse->getId(), 'toId' => 'new:Новий об\'єкт API', 'toClientId' => 'new:ТОВ Новий клієнт API', 'lines' => [['itemId' => $item->getId(), 'quantity' => 2]]]),
        ], ['files' => [new UploadedFile($tmp, 'накладна.pdf', 'application/pdf', null, true)]]);

        self::assertResponseStatusCodeSame(201);
        $movement = $this->body();
        self::assertSame('Новий об\'єкт API', $movement['to']['name']);
        self::assertSame('ТОВ Новий клієнт API', $movement['to']['client']['name']);
        self::assertSame(['накладна.pdf'], array_column($movement['documents'], 'name'));
        self::assertSame('waybill', $movement['documents'][0]['type']);
        @unlink($tmp);
    }

    public function testOpeningTheCardIsJournaledOncePerFewMinutes(): void
    {
        $manager = $this->person();
        $item = $this->item();
        $this->browser->loginUser($manager);
        $events = $this->events();
        $activity = $this->activityCount();

        // Картка й стрічка (опитування) самі нічого не пишуть.
        $this->browser->request('GET', '/api/warehouse/items/' . $item->getId());
        $this->browser->request('GET', '/api/warehouse/items/' . $item->getId() . '/feed');
        $this->browser->request('GET', '/api/warehouse/items/' . $item->getId() . '/feed');
        self::assertSame(0, $this->activityCount() - $activity);

        $this->browser->request('POST', '/api/warehouse/items/' . $item->getId() . '/viewed');
        self::assertTrue($this->body()['logged']);
        $this->browser->request('POST', '/api/warehouse/items/' . $item->getId() . '/viewed');
        self::assertFalse($this->body()['logged'], 'друге відкриття за кілька хвилин — без нового рядка');

        self::assertSame(1, $this->activityCount() - $activity);
        self::assertSame(['subject_viewed'], array_column($this->events($events), 'type'));
        $entry = $this->em->getRepository(WhActivity::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame(ActivityAction::View, $entry?->getAction());

        // Інша людина — свій рядок.
        $this->browser->loginUser($this->person(SupplyRole::Director));
        $this->browser->request('POST', '/api/warehouse/items/' . $item->getId() . '/viewed');
        self::assertTrue($this->body()['logged']);
    }

    public function testFeedShowsMovesAndScansButNotPlainViews(): void
    {
        $manager = $this->person();
        $warehouse = $this->warehouse();
        $item = $this->item(Tracking::Bulk, 'Плита ПК 60-12');
        $this->browser->loginUser($manager);

        $this->postJson('/api/warehouse/movements', ['type' => 'receipt', 'occurredAt' => '2026-11-01', 'toId' => $warehouse->getId(), 'documentNumber' => 'П-9', 'lines' => [['itemId' => $item->getId(), 'quantity' => 12]]]);
        $this->browser->request('POST', '/api/warehouse/items/' . $item->getId() . '/viewed');
        $log = self::getContainer()->get(ActivityLog::class);
        // Після запитів кернел скидає EntityManager — беремо свіжі сутності, як бот у своєму запиті.
        $log->record(ActivityAction::Scan, $this->em->find(WhItem::class, $item->getId()), $this->em->find(TelegramUser::class, $manager->getId()), WhActivity::CHANNEL_BOT, 'наклейка');

        $this->browser->request('GET', '/api/warehouse/items/' . $item->getId() . '/feed');
        $feed = $this->body();

        self::assertFalse($feed['can']['comment'], 'коментарів у позиції складу немає — лише події');
        $texts = array_column($feed['messages'], 'text');
        self::assertCount(2, $texts, implode(' | ', $texts));
        self::assertStringContainsString('Надходження №П-9 від 01.11.2026: ззовні → Тест-склад, 12 шт', $texts[0]);
        self::assertStringContainsString('скан QR', $texts[1]);
        self::assertSame(['event', 'event'], array_column($feed['messages'], 'kind'));
        self::assertTrue($feed['messages'][0]['mine']);
    }

    public function testCreateAndEditItemWriteOneEventEach(): void
    {
        $manager = $this->person();
        $category = $this->category('Вироби', 'ВР');
        $this->browser->loginUser($manager);
        $events = $this->events();

        $this->postJson('/api/warehouse/items', ['name' => 'Блок ФБС 24-4-6', 'categoryId' => $category->getId(), 'tracking' => 'bulk', 'unit' => 'шт', 'attributes' => [['name' => 'Марка бетону', 'value' => 'В15']]]);
        self::assertResponseStatusCodeSame(201);
        $item = $this->body();
        self::assertSame('ВР-0001', $item['inventoryNumber']);
        self::assertSame(['Марка бетону' => 'В15'], $item['attributes']);
        self::assertSame(['item_created'], array_column($this->events($events), 'type'));

        $events = $this->events();
        $this->browser->request('PUT', '/api/warehouse/items/' . $item['id'], server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['name' => 'Блок ФБС 24-4-6-Т', 'categoryId' => $category->getId(), 'tracking' => 'bulk', 'unit' => 'шт', 'rentalRate' => '12,5']));
        self::assertResponseIsSuccessful();
        self::assertSame('12.50', $this->body()['rentalRate']);
        $updated = $this->events($events);
        self::assertSame(['subject_updated'], array_column($updated, 'type'));
        self::assertStringContainsString('назву', (string) json_decode((string) $updated[0]['payload'], true)['summary']);

        // Помилка — 422 і картка не змінилась.
        $this->browser->request('PUT', '/api/warehouse/items/' . $item['id'], server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['name' => '', 'categoryId' => $category->getId()]));
        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertSame('Блок ФБС 24-4-6-Т', $this->em->find(WhItem::class, $item['id'])?->getName());
    }

    /** Позиція й ItemCreated — одна транзакція: не записалась подія — немає й позиції (а не «тихе» попередження в лозі). */
    public function testItemIsNotCreatedWhenItsEventCannotBeWritten(): void
    {
        $manager = $this->person();
        $category = $this->category('Вироби', 'ВР');
        $this->browser->loginUser($manager);
        $this->browser->catchExceptions(true);
        // Журнал «Плану» відмовляє саме на цій події (DDL у транзакції тесту — відкотиться).
        $this->em->getConnection()->executeStatement("ALTER TABLE plan_event ADD CONSTRAINT test_no_item_created CHECK (type <> 'item_created') NOT VALID");
        $name = 'Блок без події ' . uniqid();

        $this->postJson('/api/warehouse/items', ['name' => $name, 'categoryId' => $category->getId(), 'tracking' => 'bulk', 'unit' => 'шт']);
        self::assertResponseStatusCodeSame(500);

        $conn = $this->em->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM wh_item WHERE name = ?', [$name]), 'позиції без події немає');
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM wh_activity WHERE subject_label = ?', [$name]), 'рядка журналу складу теж');
    }

    public function testWorkerCannotWriteEvenIfHeGuessesTheUrl(): void
    {
        $item = $this->item();
        $this->browser->loginUser($this->person(SupplyRole::Worker));

        $this->postJson('/api/warehouse/items', ['name' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('POST', '/api/warehouse/items/' . $item->getId() . '/viewed');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDocumentUploadAndRemove(): void
    {
        $manager = $this->person();
        $item = $this->item();
        $this->browser->loginUser($manager);
        $tmp = tempnam(sys_get_temp_dir(), 'wh');
        file_put_contents($tmp, "\xFF\xD8\xFF фото");

        $tmp2 = tempnam(sys_get_temp_dir(), 'wh');
        copy($tmp, $tmp2);
        $events = $this->events();
        $this->browser->request('POST', '/api/warehouse/items/' . $item->getId() . '/documents', ['type' => 'photo'], ['files' => [new UploadedFile($tmp, 'щит.jpg', 'image/jpeg', null, true), new UploadedFile($tmp2, 'щит-2.jpg', 'image/jpeg', null, true)]]);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->body()['documents']);
        $added = $this->events($events);
        self::assertSame(['file_added'], array_column($added, 'type'), 'два файли одним надсиланням — одна подія');
        self::assertSame(['Фото: щит.jpg, щит-2.jpg'], json_decode((string) $added[0]['payload'], true)['names']);
        $document = array_first(array_filter($this->body()['documents'], static fn ($d) => $d['name'] === 'щит.jpg'));

        $this->browser->request('GET', $document['url']);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('inline', (string) $this->browser->getResponse()->headers->get('Content-Disposition'));

        $this->browser->request('DELETE', '/api/warehouse/documents/' . $document['id']);
        self::assertResponseIsSuccessful();
        $entry = $this->em->getRepository(WhActivity::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame(ActivityAction::DocumentRemove, $entry?->getAction());
        @unlink($tmp);
    }

    /** Склад на читання: документ відкрити можна, а додати, прибрати чи дати публічне посилання — ні. */
    public function testReaderOpensDocumentsButCannotChangeOrShareThem(): void
    {
        $item = $this->item();
        $this->browser->loginUser($this->person());
        $tmp = tempnam(sys_get_temp_dir(), 'wh');
        file_put_contents($tmp, '%PDF-1.4 накладна');
        $this->browser->request('POST', '/api/warehouse/items/' . $item->getId() . '/documents', ['type' => 'photo'], ['files' => [new UploadedFile($tmp, 'накладна.pdf', 'application/pdf', null, true)]]);
        self::assertResponseIsSuccessful();
        $document = $this->body()['documents'][0];

        $reader = $this->person(SupplyRole::Worker)->setWorkRole(WorkRole::Accountant);
        $this->em->flush();
        $this->browser->loginUser($reader);

        $this->browser->request('GET', $document['url']);
        self::assertResponseIsSuccessful();
        $this->browser->request('GET', '/api/plan/files/wh/' . $document['id']);
        self::assertResponseIsSuccessful();

        $this->browser->request('POST', '/api/plan/files/wh/' . $document['id'] . '/links', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(422);
        $this->browser->request('DELETE', '/api/warehouse/documents/' . $document['id']);
        self::assertResponseStatusCodeSame(403);
        $tmp2 = tempnam(sys_get_temp_dir(), 'wh');
        file_put_contents($tmp2, '%PDF-1.4 ще');
        $this->browser->request('POST', '/api/warehouse/items/' . $item->getId() . '/documents', ['type' => 'photo'], ['files' => [new UploadedFile($tmp2, 'ще.pdf', 'application/pdf', null, true)]]);
        self::assertResponseStatusCodeSame(403);

        // Виробничник (не продажі, не бухгалтерія) документ складу не бачить.
        $this->browser->loginUser($this->person(SupplyRole::Worker));
        $this->browser->request('GET', '/api/plan/files/wh/' . $document['id']);
        self::assertNotSame(200, $this->browser->getResponse()->getStatusCode());
        @unlink($tmp);
        @unlink($tmp2);
    }

    public function testMentionsRespectTaskVisibility(): void
    {
        $item = $this->item(Tracking::Bulk, 'Кільце колодязне КС 10.9');
        self::assertSame([ItemMentions::normalize($item->getInventoryNumber()), 'кс10-9'], ItemMentions::codes($item));

        $director = $this->person(SupplyRole::Director)->setWorkRole(WorkRole::Director);
        $manager = $this->person()->setWorkRole(WorkRole::Manager);
        $task = new Task('Кільця КС 10-9 — 40 шт для каналізації', $director);
        $this->em->persist($task);
        $this->em->flush();

        $this->browser->loginUser($director);
        $this->browser->request('GET', '/api/warehouse/items/' . $item->getId());
        self::assertSame([$task->getId()], array_column($this->body()['mentions']['tasks'], 'id'));

        $this->browser->loginUser($manager);
        $this->browser->request('GET', '/api/warehouse/items/' . $item->getId());
        self::assertSame([], $this->body()['mentions']['tasks'], 'чужа задача менеджеру не видна');
    }

    public function testPlanLinksWarehouseSubjectsToTheVueCard(): void
    {
        $subjects = new WarehouseSubjects(
            $this->em,
            self::getContainer()->get(TelegramUserRepository::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
            new TasksSection(true, 'tasks'),
        );
        self::assertSame('/crm/sklad/items/7', $subjects->url(SubjectRef::of(SubjectType::WhItem, 7)));
        self::assertSame('/crm/sklad/movements/3', $subjects->url(SubjectRef::of(SubjectType::WhMovement, 3)));

        $stand = new WarehouseSubjects($this->em, self::getContainer()->get(TelegramUserRepository::class), self::getContainer()->get(UrlGeneratorInterface::class), new TasksSection(false, null));
        self::assertSame('/sklad/items/7', $stand->url(SubjectRef::of(SubjectType::WhItem, 7)), 'стенд заявок — Twig-склад як і був');
    }

    /** @param array<string, mixed> $body */
    private function postJson(string $url, array $body): void
    {
        $this->browser->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return json_decode((string) $this->browser->getResponse()->getContent(), true) ?? [];
    }

    /**
     * Без аргументу — останній id plan_event; з аргументом — події після нього.
     *
     * @return ($after is null ? int : list<array<string, mixed>>)
     */
    private function events(?int $after = null): int|array
    {
        $conn = $this->em->getConnection();

        if ($after === null) {
            return (int) $conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM plan_event');
        }

        return $conn->fetchAllAssociative('SELECT type, subject_type, payload FROM plan_event WHERE id > ? ORDER BY id', [$after]);
    }

    private function activityCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wh_activity');
    }
}
