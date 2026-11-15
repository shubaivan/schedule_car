<?php

namespace App\Tests\Plan;

use App\Entity\Car;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Dto\PurchaseInput;
use App\Supply\Entity\Department;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\RecordPurchase;
use App\Supply\Service\SupplierDirectory;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskMember;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\TaskService;
use App\Tests\Warehouse\WarehouseFixtures;
use App\Warehouse\Enum\MovementType;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Service\RecordMovement;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * «Огляд» v2 (/api/plan/overview) і список, куди ведуть його числа (/api/tasks/list):
 * числа збігаються зі списком, видимість за роллю, рішення в рядку — лише ті, що людина
 * справді може ухвалити, гроші й склад — лише тим, кому належить.
 * Усе в транзакції тесту (клієнт без перезавантаження ядра — те саме зʼєднання).
 */
class OverviewApiTest extends WebTestCase
{
    use WarehouseFixtures;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    protected function em(): EntityManagerInterface
    {
        return $this->em;
    }

    public function testDirectorSeesDepartmentMatrixThatMatchesTheList(): void
    {
        $director = $this->user(WorkRole::Director, SupplyRole::Director);
        $sales = $this->department('Продажі');
        $prod = $this->department('Виробництво');
        $empty = $this->department('Порожній');
        $worker = $this->user(WorkRole::Production, SupplyRole::Worker, $prod);

        // Задача в двох відділах: рахується в кожному, але в «усього» — один раз.
        $both = $this->task('Сходові марші', $director, [$sales, $prod], due: '-2 days');
        $this->task('Плити ПК', $director, [$sales]);
        $stale = $this->task('ФБС 24-4-6', $director, [$prod], activity: '-5 days');
        $this->task('Закрита', $director, [$sales], status: TaskStatus::Done, due: '-3 days');
        // Відділ учасника не рахується — лише позначений у задачі (інакше «Дирекція» роздувається).
        $this->task('Лише учасник з виробництва', $director, [$sales], members: [$worker]);

        $this->client->loginUser($director);
        $data = $this->get('/api/plan/overview');

        self::assertTrue($data['scope']['all']);
        $rows = array_column($data['control']['rows'], null, 'id');
        self::assertSame(['open' => 3, 'overdue' => 1, 'stale' => 0], $this->counts($rows[$sales->getId()]));
        self::assertSame(['open' => 2, 'overdue' => 1, 'stale' => 1], $this->counts($rows[$prod->getId()]));
        // Директор бачить і відділ без задач — нуль теж відповідь.
        self::assertSame(['open' => 0, 'overdue' => 0, 'stale' => 0], $this->counts($rows[$empty->getId()]));

        // Числа з матриці відкривають рівно стільки задач у списку.
        foreach (['open' => 2, 'overdue' => 1, 'stale' => 1] as $f => $expected) {
            $list = $this->get('/api/tasks/list?f=' . $f . '&dep=' . $prod->getId());
            self::assertSame($expected, $list['total'], $f);
            self::assertCount($expected, $list['items'], $f);
        }

        self::assertSame([$stale->getId()], array_column($this->get('/api/tasks/list?f=stale&dep=' . $prod->getId())['items'], 'id'));
        self::assertSame($prod->getName(), $this->get('/api/tasks/list?dep=' . $prod->getId())['filter']['departmentName']);
        self::assertContains($both->getId(), array_column($this->get('/api/tasks/list?f=overdue')['items'], 'id'));
        self::assertGreaterThanOrEqual(1, $data['kpi']['overdueTasks']);
    }

    public function testTaskWithoutAnyDepartmentIsListedUnderNone(): void
    {
        $director = $this->user(WorkRole::Director, SupplyRole::Director);
        $loose = $this->task('Без відділу', $director, []);
        $worker = $this->user(WorkRole::Production, SupplyRole::Worker, $this->department('Цех'));
        $withMember = $this->task('Учасник з цеху, відділ не позначено', $director, [], members: [$worker]);
        $this->client->loginUser($director);
        self::assertContains($withMember->getId(), array_column($this->get('/api/tasks/list?dep=none')['items'], 'id'));

        self::assertContains($loose->getId(), array_column($this->get('/api/tasks/list?dep=none')['items'], 'id'));
        self::assertGreaterThanOrEqual(1, $this->get('/api/plan/overview')['control']['none']['open']);
        self::assertSame('Без відділу', $this->get('/api/tasks/list?dep=none')['filter']['departmentName']);

        $this->client->request('GET', '/api/tasks/list?dep=999999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testWorkerSeesOnlyOwnScopeNoDecisionsNoMoney(): void
    {
        $director = $this->user(WorkRole::Director, SupplyRole::Director);
        $prod = $this->department('Цех');
        $other = $this->department('Чужий');
        $worker = $this->user(WorkRole::Production, SupplyRole::Worker, $prod);

        $mine = $this->task('Моя задача', $director, [$prod], members: [$worker]);
        $this->task('Чужа задача', $director, [$other], due: '-1 day');
        $this->task('На погодженні', $director, [$other], status: TaskStatus::Approval);

        $this->client->loginUser($worker);
        $data = $this->get('/api/plan/overview');

        self::assertFalse($data['scope']['all']);
        self::assertFalse($data['scope']['decideSupply']);
        self::assertSame([], $data['decisions']['items']);
        self::assertSame(0, $data['decisions']['total']);
        self::assertNull($data['supply']['month'], 'гроші постачання — лише тим, хто веде або погоджує');
        self::assertNull($data['warehouse'], 'склад — лише тим, хто його веде');
        self::assertSame(0, $data['kpi']['overdueTasks']);

        $ids = array_column($data['control']['rows'], 'id');
        self::assertContains($prod->getId(), $ids);
        self::assertNotContains($other->getId(), $ids, 'чужий відділ без моїх задач не показуємо');
        self::assertSame([$mine->getId()], array_column($this->get('/api/tasks/list')['items'], 'id'));
        self::assertSame([], $this->get('/api/tasks/list?dep=' . $other->getId())['items']);
        self::assertSame([$mine->getId()], array_column($data['mine'], 'id'));
    }

    public function testAwaitingMyReplyListsMentionsUntilIAnswer(): void
    {
        $director = $this->user(WorkRole::Director, SupplyRole::Director);
        $manager = $this->user(WorkRole::Manager, SupplyRole::Manager);
        $other = $this->user(WorkRole::Production, SupplyRole::Worker);
        $task = $this->task('Знижка 3% для ТОВ Будмакс', $manager, [], members: [$director]);
        $closed = $this->task('Закрита', $manager, [], status: TaskStatus::Done, members: [$director]);
        $service = self::getContainer()->get(TaskService::class);

        $first = $service->post($task, $manager, 'Погоджуємо знижку 3%?', [], [(int) $director->getId()]);
        $second = $service->post($task, $manager, "І термін —\nдо пʼятниці?", [], [(int) $director->getId(), (int) $other->getId()]);
        $service->post($task, $manager, 'Без відмітки — не питання до директора.');
        $service->post($closed, $manager, 'Закрита задача — не чекає відповіді', [], [(int) $director->getId()]);

        $this->client->loginUser($director);
        $data = $this->get('/api/plan/overview');
        self::assertSame(2, $data['replies']['total']);
        self::assertSame(2, $data['kpi']['replies']);
        // Найстаріше питання — першим; посилання веде на повідомлення в задачі.
        self::assertSame([$first->getId(), $second->getId()], array_column($data['replies']['items'], 'messageId'));
        $row = $data['replies']['items'][1];
        self::assertSame($task->getId(), $row['taskId']);
        self::assertSame('І термін — до пʼятниці?', $row['excerpt']);
        self::assertTrue($row['unread']);
        self::assertNotNull($row['author']);
        // Рішення директора в задачах немає: у черзі рішень лише заявки.
        self::assertNull($this->decision($data, 'task', (int) $task->getId()));
        self::assertArrayNotHasKey('tasks', $data['decisions']);

        // Відмітили не мене — у мене порожньо.
        $this->client->loginUser($other);
        self::assertSame([$second->getId()], array_column($this->get('/api/plan/overview')['replies']['items'], 'messageId'));

        // Директор відповів у задачі — обидва питання закриті.
        $director = $this->em->find(TelegramUser::class, $director->getId()) ?? self::fail('director');
        $task = $this->em->find(Task::class, $task->getId()) ?? self::fail('task');
        $service->post($task, $director, 'Так, погоджую.');
        $this->client->loginUser($director);
        $data = $this->get('/api/plan/overview');
        self::assertSame(0, $data['replies']['total']);
        self::assertSame([], $data['replies']['items']);
    }

    public function testTaskStatusesHaveNoDirectorDecision(): void
    {
        $director = $this->user(WorkRole::Director, SupplyRole::Director);
        $manager = $this->user(WorkRole::Manager, SupplyRole::Manager);
        $task = $this->task('Нова від менеджера', $manager, [], status: TaskStatus::New);
        $legacy = $this->task('Стара «на погодженні»', $manager, [], status: TaskStatus::Approval);

        $this->client->loginUser($manager);
        $detail = $this->get('/api/tasks/' . $task->getId());
        self::assertNotContains('approval', array_column($detail['can']['transitions'], 'value'), 'на погодження директору більше не відправляють');
        self::assertArrayNotHasKey('awaitsDecision', $detail);
        // Стара задача «на погодженні» не застрягає: автор сам виводить її в роботу.
        self::assertSame(['in_work', 'done', 'rejected'], array_column($this->get('/api/tasks/' . $legacy->getId())['can']['transitions'], 'value'));
        $this->post('/api/tasks/' . $legacy->getId() . '/status', ['to' => 'in_work']);
        self::assertResponseIsSuccessful();
        $this->post('/api/tasks/' . $task->getId() . '/status', ['to' => 'approval']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSupplyDecisionRequiresReasonToReject(): void
    {
        $director = $this->user(WorkRole::Director, SupplyRole::Director);
        $manager = $this->user(WorkRole::Manager, SupplyRole::Manager);
        $request = $this->awaitingApproval($manager);

        $this->client->loginUser($director);
        $data = $this->get('/api/plan/overview');
        $row = $this->decision($data, 'supply', (int) $request->getId());
        self::assertNotNull($row);
        self::assertSame(['approved', 'rejected'], array_column($row['actions'], 'to'));
        self::assertSame([false, true], array_column($row['actions'], 'reasonRequired'));
        self::assertEqualsWithDelta(4200.0, $row['amount'], 0.001);
        self::assertGreaterThanOrEqual(4200.0, $data['supply']['month']['spent']);

        $this->post('/api/supply/requests/' . $request->getId() . '/status', ['to' => 'rejected']);
        self::assertResponseStatusCodeSame(422);
        $this->post('/api/supply/requests/' . $request->getId() . '/status', ['to' => 'rejected', 'comment' => 'Дорого']);
        self::assertResponseIsSuccessful();

        // «В списку очікування» напряму не погоджується — у рядку «Повернути на затвердження».
        // Після запитів клієнта менеджер сутностей очищений — беремо людей заново.
        $manager = $this->em->find(TelegramUser::class, $manager->getId()) ?? self::fail('manager');
        $director = $this->em->find(TelegramUser::class, $director->getId()) ?? self::fail('director');
        $waiting = $this->awaitingApproval($manager);
        self::getContainer()->get(ChangeStatus::class)($waiting, SupplyStatus::Waiting, $director);
        $row = $this->decision($this->get('/api/plan/overview'), 'supply', (int) $waiting->getId());
        self::assertSame(['approval', 'rejected'], array_column($row['actions'] ?? [], 'to'));
        self::assertSame('Повернути на затвердження', $row['actions'][0]['label']);

        // Менеджер постачання бачить гроші місяця, але не кнопки директора.
        $this->client->loginUser($manager);
        $data = $this->get('/api/plan/overview');
        self::assertNotNull($data['supply']['month']);
        self::assertFalse($data['scope']['decideSupply']);
    }

    public function testLowStockCountsOnlyWarehouses(): void
    {
        $manager = $this->user(WorkRole::Manager, SupplyRole::Manager);
        $warehouse = $this->warehouse();
        $object = $this->site();
        $locks = $this->item(Tracking::Bulk, 'Замки клинові')->setMinQuantity(100);
        $ties = $this->item(Tracking::Bulk, 'Стяжки')->setMinQuantity(10);
        $this->em->flush();

        $record = self::getContainer()->get(RecordMovement::class);
        $record(MovementType::Receipt, new DateTime(), null, $warehouse, [['item' => $locks, 'quantity' => 150], ['item' => $ties, 'quantity' => 30]], $manager);
        // 90 замків поїхали на обʼєкт: на складі 60 < 100 — нестача; стяжок 30 ≥ 10 — ні.
        $record(MovementType::Shipment, new DateTime(), $warehouse, $object, [['item' => $locks, 'quantity' => 90]], $manager);

        $this->client->loginUser($manager);
        $data = $this->get('/api/plan/overview');
        $low = array_column($data['warehouse']['low'], null, 'id');
        self::assertSame(['id' => $locks->getId(), 'name' => 'Замки клинові', 'unit' => 'шт', 'quantity' => 60, 'min' => 100], $low[$locks->getId()]);
        self::assertArrayNotHasKey($ties->getId(), $low);
        self::assertGreaterThanOrEqual(1, $data['kpi']['shipmentsToday']);

        $ids = array_column($this->get('/api/warehouse/items?place=low')['items'], 'id');
        self::assertContains($locks->getId(), $ids);
        self::assertNotContains($ties->getId(), $ids);

        // Поріг ставиться з форми позиції і знімається порожнім полем.
        $this->client->request('PUT', '/api/warehouse/items/' . $ties->getId(), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], content: (string) json_encode([
            'name' => 'Стяжки', 'categoryId' => $ties->getCategory()->getId(), 'tracking' => 'bulk', 'unit' => 'шт', 'minQuantity' => '40',
        ]));
        self::assertResponseIsSuccessful();
        self::assertSame(40, $this->json()['minQuantity']);
        self::assertContains($ties->getId(), array_column($this->get('/api/plan/overview')['warehouse']['low'], 'id'));
    }

    public function testFleetShowsCarsBookedTodayAndTomorrow(): void
    {
        $director = $this->user(WorkRole::Director, SupplyRole::Director);
        $car = new Car()->setCarNumber('ВО ' . random_int(1000, 9999) . ' ТТ');
        $this->em->persist($car);
        $today = new DateTime('today 10:00', new DateTimeZone('Europe/Kyiv'));
        $this->book($car, $director, $today);
        $this->book($car, $director, (clone $today)->modify('+1 hour'));
        $this->em->flush();

        $this->client->loginUser($director);
        $data = $this->get('/api/plan/overview');

        self::assertTrue($data['fleet']['enabled']);
        $cars = array_column($data['fleet']['cars'], null, 'id');
        self::assertSame([10, 11], $cars[$car->getId()]['today']);
        self::assertSame([], $cars[$car->getId()]['tomorrow']);
        self::assertGreaterThanOrEqual(1, $data['kpi']['fleet']['busy']);
    }

    public function testOverviewNeedsLogin(): void
    {
        $this->client->request('GET', '/api/plan/overview');
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{open: int, overdue: int, stale: int}
     */
    private function counts(array $row): array
    {
        return ['open' => $row['open'], 'overdue' => $row['overdue'], 'stale' => $row['stale']];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    private function decision(array $data, string $kind, int $id): ?array
    {
        foreach ($data['decisions']['items'] as $row) {
            if ($row['kind'] === $kind && $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    private function user(WorkRole $role, SupplyRole $supply, ?Department $department = null): TelegramUser
    {
        $user = new TelegramUser()
            ->setTelegramId('overview-' . uniqid('', true))
            ->setFirstName('Огляд')
            ->setLastName($role->label())
            ->setWorkRole($role)
            ->setSupplyRole($supply)
            ->setDepartment($department);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function department(string $name): Department
    {
        $department = new Department()->setName($name . ' ' . uniqid());
        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    /**
     * @param Department[] $departments
     * @param TelegramUser[] $members
     */
    private function task(string $title, TelegramUser $creator, array $departments, TaskStatus $status = TaskStatus::InWork, ?string $due = null, array $members = [], ?string $activity = null): Task
    {
        $task = new Task($title, $creator);
        $task->setStatus($status);

        if ($due !== null) {
            $task->setDueAt(Task::now()->modify($due));
        }

        foreach ($departments as $department) {
            $task->addDepartment($department);
        }

        $this->em->persist($task);

        foreach ($members as $member) {
            $this->em->persist(new TaskMember($task, $member));
        }

        $task->touch($activity !== null ? Task::now()->modify($activity) : null);
        $this->em->flush();
        $this->em->refresh($task);

        return $task;
    }

    private function book(Car $car, TelegramUser $user, DateTime $at): void
    {
        $set = new ScheduledSet()
            ->setCar($car)
            ->setTelegramUserId($user)
            ->setScheduledAt($at)
            ->setYear((int) $at->format('Y'))
            ->setMonth((int) $at->format('n'))
            ->setDay((int) $at->format('j'))
            ->setHour((int) $at->format('G'));
        $this->em->persist($set);
    }

    private function awaitingApproval(TelegramUser $manager): SupplyRequest
    {
        $container = self::getContainer();
        $change = $container->get(ChangeStatus::class);
        $request = ($container->get(CreateRequest::class))($manager, new CreateRequestInput(item: 'Цемент М500', quantity: '20', unit: Unit::Pack));
        $change($request, SupplyStatus::InProgress, $manager);
        ($container->get(RecordPurchase::class))($request, $manager, new PurchaseInput(
            supplier: $container->get(SupplierDirectory::class)->findOrCreate('ФОП Огляд ' . uniqid()),
            totalAmount: '4200',
        ), notify: false);
        $change($request, SupplyStatus::Approval, $manager);

        return $request;
    }

    /** @return array<string, mixed> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return $this->json();
    }

    /** @param array<string, mixed> $body */
    private function post(string $url, array $body): void
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
