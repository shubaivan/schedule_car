<?php

namespace App\Tests\Tasks;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Security\LoginIdentity;
use App\Supply\Entity\Department;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\PreviewGenerator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Розділ «Задачі»: вхід за паролем, видимість, відділ → усі його люди,
 * рішення директора, чат із файлами й превʼю, кошик співробітників.
 */
class TasksApiTest extends WebTestCase
{
    private const PREFIX = 'tasks-test-';
    private const PASSWORD = 'correct-horse-42';

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
            $conn->executeStatement("UPDATE telegram_user SET archived_by_id = NULL WHERE id IN ($list)");
            $conn->executeStatement("DELETE FROM login_token WHERE user_id IN ($list)");
            $conn->executeStatement("DELETE FROM plan_event WHERE type = 'staff_changed' AND subject_id IN ($list)");
            $conn->executeStatement("DELETE FROM telegram_user WHERE id IN ($list)");
        }

        $conn->executeStatement("DELETE FROM supply_department WHERE name LIKE 'Тест-відділ %'");

        parent::tearDown();
    }

    public function testPasswordLogin(): void
    {
        $user = $this->user(WorkRole::Manager, 'login');

        $this->client->request('GET', '/vhid');
        self::assertResponseIsSuccessful();
        $token = $this->client->getCrawler()->filter('input[name=_csrf_token]')->attr('value');

        $this->client->request('POST', '/vhid', ['login' => $user->getEmail(), 'password' => 'wrong-password', '_csrf_token' => $token]);
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('Невірний логін або пароль', (string) $this->client->getResponse()->getContent());

        $token = $this->client->getCrawler()->filter('input[name=_csrf_token]')->attr('value');
        $this->client->request('POST', '/vhid', ['login' => strtoupper((string) $user->getEmail()), 'password' => self::PASSWORD, '_csrf_token' => $token]);
        self::assertResponseRedirects();

        $this->client->request('GET', '/api/tasks/meta');
        self::assertResponseIsSuccessful();
        self::assertSame($user->getId(), $this->json()['me']['id']);
    }

    public function testLoginThrottleCountsAllSpellingsOfOnePhone(): void
    {
        $user = $this->user(WorkRole::Manager, 'phone');
        $tail = sprintf('%07d', random_int(0, 9999999));
        $user->setPhoneNumber('38067' . $tail);
        $this->em->flush();

        // П'ять різних записів того самого номера — одна людина, одне вікно з 5 спроб.
        $spellings = ['067' . $tail, '+38067' . $tail, '067 ' . substr($tail, 0, 3) . ' ' . substr($tail, 3), '(067)' . $tail, '+38 (067) ' . $tail];

        foreach ($spellings as $login) {
            $this->client->request('GET', '/vhid');
            $token = $this->client->getCrawler()->filter('input[name=_csrf_token]')->attr('value');
            $this->client->request('POST', '/vhid', ['login' => $login, 'password' => 'wrong-password', '_csrf_token' => $token]);
            self::assertResponseStatusCodeSame(401);
            self::assertStringContainsString('Невірний логін або пароль', (string) $this->client->getResponse()->getContent());
        }

        // Шостий запис, уже з правильним паролем, — пауза, а не вхід.
        $this->client->request('GET', '/vhid');
        $token = $this->client->getCrawler()->filter('input[name=_csrf_token]')->attr('value');
        $this->client->request('POST', '/vhid', ['login' => '8067' . $tail, 'password' => self::PASSWORD, '_csrf_token' => $token]);
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('Забагато спроб', (string) $this->client->getResponse()->getContent());
    }

    public function testLoginIdentityNormalisation(): void
    {
        self::assertSame('phone:671234567', LoginIdentity::normalize(' +38 (067) 123-45-67 '));
        self::assertSame('phone:671234567', LoginIdentity::normalize('0671234567'));
        self::assertSame('ivan@zavod.test', LoginIdentity::normalize(' Ivan@Zavod.TEST '));
        self::assertSame('abc', LoginIdentity::normalize('ABC'));
        self::assertSame('671234567', LoginIdentity::phoneTail('phone:671234567'));
        self::assertNull(LoginIdentity::phoneTail('ivan@zavod.test'));
    }

    public function testArchivedUserCannotLogIn(): void
    {
        $user = $this->user(WorkRole::Manager, 'gone');
        $user->archive();
        $this->em->flush();

        $this->client->request('GET', '/vhid');
        $token = $this->client->getCrawler()->filter('input[name=_csrf_token]')->attr('value');
        $this->client->request('POST', '/vhid', ['login' => $user->getEmail(), 'password' => self::PASSWORD, '_csrf_token' => $token]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testDepartmentAddsAllItsPeopleAndOutsidersSeeNothing(): void
    {
        $department = (new Department())->setName('Тест-відділ ' . uniqid());
        $this->em->persist($department);
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $worker1 = $this->user(WorkRole::Production, 'w1', $department);
        $worker2 = $this->user(WorkRole::Production, 'w2', $department);
        $outsider = $this->user(WorkRole::Accountant, 'out');
        $director = $this->user(WorkRole::Director, 'dir');

        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Плити для обʼєкта', 'departmentIds' => [$department->getId()], 'dueAt' => '2030-01-15']);
        self::assertResponseStatusCodeSame(201);

        $members = array_column($task['members'], 'id');
        self::assertContains($worker1->getId(), $members);
        self::assertContains($worker2->getId(), $members);
        self::assertContains($manager->getId(), $members);
        self::assertSame([$department->getName()], array_column($task['departments'], 'name'));

        $this->login($outsider);
        $this->client->request('GET', '/api/tasks/' . $task['id']);
        self::assertResponseStatusCodeSame(403);
        $board = $this->get('/api/tasks/board');
        self::assertNotContains($task['id'], array_column($board['new'], 'id'));

        $this->login($worker1);
        $board = $this->get('/api/tasks/board');
        self::assertContains($task['id'], array_column($board['new'], 'id'));

        // Директор бачить усе, навіть якщо його не позначили.
        $this->login($director);
        $this->client->request('GET', '/api/tasks/' . $task['id']);
        self::assertResponseIsSuccessful();
    }

    public function testOnlyDirectorDecides(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $director = $this->user(WorkRole::Director, 'dir');

        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Знижка 3%']);
        $task = $this->send('POST', '/api/tasks/' . $task['id'] . '/status', ['to' => 'in_work']);
        self::assertSame('in_work', $task['status']);
        $task = $this->send('POST', '/api/tasks/' . $task['id'] . '/status', ['to' => 'approval']);
        self::assertSame('approval', $task['status']);
        self::assertSame([], $task['can']['transitions'], 'Менеджер не бачить кнопок рішення');

        $this->send('POST', '/api/tasks/' . $task['id'] . '/status', ['to' => 'approved']);
        self::assertResponseStatusCodeSame(422);

        $this->login($director);
        $detail = $this->get('/api/tasks/' . $task['id']);
        self::assertSame(['approved', 'waiting', 'rejected'], array_column($detail['can']['transitions'], 'value'));
        $task = $this->send('POST', '/api/tasks/' . $task['id'] . '/status', ['to' => 'approved', 'comment' => 'Погоджую']);
        self::assertSame('approved', $task['status']);
        self::assertSame('Погоджую', end($task['messages'])['text']);
    }

    public function testOverviewDedupesTasksAndCountsMatchChronicle(): void
    {
        $manager = $this->user(WorkRole::Manager, 'ovm');
        $director = $this->user(WorkRole::Director, 'ovd');

        $this->login($manager);
        $yesterday = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
        $a = $this->send('POST', '/api/tasks', ['title' => 'Огляд: на погодженні й прострочена', 'dueAt' => $yesterday, 'memberIds' => [$director->getId()]]);
        $this->send('POST', '/api/tasks/' . $a['id'] . '/status', ['to' => 'in_work']);
        $this->send('POST', '/api/tasks/' . $a['id'] . '/status', ['to' => 'approval']);
        $this->send('POST', '/api/tasks', ['title' => 'Огляд: звичайна', 'memberIds' => [$director->getId()]]);

        $mine = $this->get('/api/tasks/dashboard');
        self::assertGreaterThanOrEqual(2, $mine['stats']['mine']);
        self::assertSame([], $mine['decisions'], 'Менеджер не вирішує — блоку рішень немає');
        self::assertArrayHasKey('supply', $mine);
        self::assertNull($mine['warehouse'], 'Склад вимкнено в тестовому оточенні — блоку немає');

        $this->login($director);
        $dash = $this->get('/api/tasks/dashboard');
        $blocks = ['decisions', 'overdue', 'mine', 'department', 'recent'];
        $where = [];

        foreach ($blocks as $block) {
            foreach ($dash[$block] as $item) {
                $where[$item['id']][] = $block;
            }
        }

        self::assertSame(['decisions'], $where[$a['id']] ?? null, 'Задача на погодженні й прострочена — лише в «Рішеннях», без повторів');

        foreach ($where as $id => $in) {
            self::assertCount(1, $in, "Задача {$id} в одному блоці, а не в " . implode(', ', $in));
        }

        $chronicle = $this->get('/api/plan/chronicle/counts');
        self::assertSame($chronicle['tasks'], $dash['stats']['unread'], '«З новим» в Огляді — те саме число, що біля «Хроніки»');
        self::assertGreaterThan(0, $dash['stats']['overdue']);
        self::assertGreaterThanOrEqual(1, $dash['stats']['decisions']);
    }

    public function testChatWithFilesAndPreviews(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $member = $this->user(WorkRole::Production, 'mem');
        $outsider = $this->user(WorkRole::Production, 'out');

        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Фото й рахунок', 'memberIds' => [$member->getId()]]);

        $dir = self::getContainer()->getParameter('kernel.project_dir') . '/demo/tasks/';
        $this->client->request('POST', '/api/tasks/' . $task['id'] . '/messages', [
            'text' => 'Дивись, @' . $member->shortName(),
            'mentions' => [$member->getId()],
        ], [
            'files' => [
                $this->upload($dir . 'Плити_склад.jpg', 'image/jpeg'),
                $this->upload($dir . 'Рахунок_на_оплату_118.pdf', 'application/pdf'),
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
        $message = $this->json();

        [$image, $pdf] = $message['attachments'];

        // Upload temp files are 0600; the stored original must stay readable for the group
        // (CLI/worker as deploy: previews, Drive copies) — «План» 14.11.
        $stored = glob(self::getContainer()->getParameter('kernel.project_dir') . '/var/storage/tasks/test/files/*/*/' . $image['id'] . '-*');
        self::assertCount(1, $stored);
        self::assertSame(0o660, fileperms($stored[0]) & 0o777);

        self::assertSame('image', $image['kind']);
        self::assertSame('ready', $image['preview']);
        self::assertSame(1400, $image['width']);
        self::assertSame('pdf', $pdf['kind']);

        if (PreviewGenerator::available()['pdftoppm']) {
            self::assertSame('ready', $pdf['preview']);
            self::assertSame(1, $pdf['pages']);
            $this->client->request('GET', $pdf['urls']['page'] . '1');
            self::assertResponseIsSuccessful();
        }

        $this->client->request('GET', $image['urls']['thumb']);
        self::assertResponseIsSuccessful();
        self::assertSame('image/jpeg', $this->client->getResponse()->headers->get('Content-Type'));

        // Учасник бачить, а згадка кладе задачу в його «Мої повідомлення».
        $this->login($member);
        $this->client->request('GET', $image['urls']['view']);
        self::assertResponseIsSuccessful();
        self::assertContains($task['id'], array_column($this->get('/api/tasks/board')['messages'], 'id'));
        // «+N» — як у Хроніці: подія «створено» + коментар (TaskFeedRepository::UNREAD).
        self::assertSame(2, $this->unread($task['id']));

        // Чужий — ні.
        $this->login($outsider);
        $this->client->request('GET', $image['urls']['download']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDangerousFilesAreRefusedByContentNotByClaimedType(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'HTML у вкладенні']);
        $messages = fn () => (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM task_message WHERE task_id = ? AND kind = ?', [$task['id'], 'comment']);

        // HTML, SVG і HTML, що прикидається PDF, — відмова за вмістом, без повідомлення й файла.
        foreach ([['x.html', 'text/html', '<html><script>alert(1)</script></html>'], ['logo.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'], ['invoice.pdf', 'application/pdf', '<!doctype html><html><body><script>alert(1)</script></body></html>']] as [$name, $mime, $body]) {
            $path = tempnam(sys_get_temp_dir(), 'bad');
            file_put_contents($path, $body);
            $this->client->request('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'дивіться'], ['files' => [new UploadedFile($path, $name, $mime, null, true)]]);
            self::assertResponseStatusCodeSame(422, $name);
            self::assertStringContainsString('такий тип файла не приймаємо', (string) ($this->json()['error'] ?? ''));
        }

        self::assertSame(0, $messages(), 'нічого не записано');

        // Справжній PDF і офісний файл, який finfo бачить як контейнер, — приймаємо.
        $docx = self::getContainer()->getParameter('kernel.project_dir') . '/demo/tasks/Комерційна_пропозиція_КП-77.docx';
        $this->client->request('POST', '/api/tasks/' . $task['id'] . '/messages', [], ['files' => [$this->upload($docx, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]]);
        self::assertResponseIsSuccessful();
        $file = $this->json()['attachments'][0];
        self::assertSame('office', $file['kind']);
        $this->client->request('GET', $file['urls']['inline']);
        self::assertStringStartsWith('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testTrashKeepsPeopleAndOnlyAdminManagesIt(): void
    {
        $admin = $this->user(WorkRole::Admin, 'adm');
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $leaving = $this->user(WorkRole::Production, 'leave');

        $this->login($manager);
        $this->send('POST', '/api/staff/' . $leaving->getId() . '/trash', []);
        self::assertResponseStatusCodeSame(403);

        $this->login($admin);
        $row = $this->send('POST', '/api/staff/' . $leaving->getId() . '/trash', []);
        self::assertNotNull($row['archivedAt']);
        self::assertSame($admin->shortName(), $row['archivedBy']);
        self::assertContains($leaving->getId(), array_column($this->get('/api/staff?trash=1')['items'], 'id'));
        self::assertNotContains($leaving->getId(), array_column($this->get('/api/staff')['items'], 'id'));

        $this->send('POST', '/api/staff/' . $leaving->getId() . '/restore', []);
        self::assertContains($leaving->getId(), array_column($this->get('/api/staff')['items'], 'id'));
    }

    public function testStaleTaskStatusIsRejectedUnderRowLock(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $director = $this->user(WorkRole::Director, 'dir');
        $this->login($manager);
        $id = $this->send('POST', '/api/tasks', ['title' => 'Дві кнопки одночасно'])['id'];
        $this->send('POST', '/api/tasks/' . $id . '/status', ['to' => 'approval']);
        self::assertResponseIsSuccessful();

        $task = $this->em->find(\App\Tasks\Entity\Task::class, $id);
        self::assertSame('approval', $task->getStatus()->value);
        // Інший директор уже відхилив (інший запит): у базі rejected, у пам'яті — approval.
        $this->em->getConnection()->executeStatement("UPDATE task SET status = 'rejected' WHERE id = ?", [$id]);
        $events = (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM plan_event WHERE subject_type = 'task' AND subject_id = ?", [$id]);

        try {
            self::getContainer()->get(\App\Tasks\Service\TaskService::class)->changeStatus($task, $director, \App\Tasks\Enum\TaskStatus::from('approved'));
            self::fail('другий перехід з того самого «from» має відмовити');
        } catch (\App\Tasks\Exception\TaskException $e) {
            self::assertStringContainsString('щойно змінили', $e->getMessage());
        }

        self::assertSame($events, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM plan_event WHERE subject_type = 'task' AND subject_id = ?", [$id]));
        self::assertSame('rejected', $this->em->getConnection()->fetchOne('SELECT status FROM task WHERE id = ?', [$id]));
    }

    public function testAdminCannotTakeOverSuperadminOrAnotherAdmin(): void
    {
        $admin = $this->user(WorkRole::Admin, 'adm');
        $colleague = $this->user(WorkRole::Admin, 'adm2');
        $boss = $this->user(WorkRole::Director, 'sa');
        $boss->setSuperadmin(true);
        $worker = $this->user(WorkRole::Production, 'wrk');
        $this->em->flush();

        $this->login($admin);

        // Суперадмін: ні пароля, ні email, ні ролі, ні навіть імені; і не в кошик.
        foreach ([['password' => 'taken-over-123'], ['email' => 'evil@example.test'], ['role' => 'production'], ['firstName' => 'Інший']] as $body) {
            $this->send('PATCH', '/api/staff/' . $boss->getId(), $body);
            self::assertResponseStatusCodeSame(403, (string) json_encode($body));
        }

        $this->send('POST', '/api/staff/' . $boss->getId() . '/trash', []);
        self::assertResponseStatusCodeSame(403);

        // Колега-адмін: облікові дані й роль — ні, відділ/посаду — так; та сама картка цілком без змін — так.
        foreach ([['password' => 'taken-over-123'], ['email' => 'evil2@example.test'], ['phone' => '+380671112233'], ['role' => 'director']] as $body) {
            $this->send('PATCH', '/api/staff/' . $colleague->getId(), $body);
            self::assertResponseStatusCodeSame(403, (string) json_encode($body));
        }

        $this->send('PATCH', '/api/staff/' . $colleague->getId(), ['position' => 'Логіст', 'email' => $colleague->getEmail(), 'role' => 'admin']);
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/staff/' . $colleague->getId() . '/trash', []);
        self::assertResponseStatusCodeSame(403);

        // Звичайна людина й сам собі — як і раніше.
        $this->send('PATCH', '/api/staff/' . $worker->getId(), ['password' => 'new-password-1', 'role' => 'manager']);
        self::assertResponseIsSuccessful();
        $this->send('PATCH', '/api/staff/' . $admin->getId(), ['password' => 'my-new-password-1']);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $fresh = $this->em->find(TelegramUser::class, $boss->getId());
        self::assertTrue($hasher->isPasswordValid($fresh, self::PASSWORD), 'пароль суперадміна не змінився');
        self::assertSame(WorkRole::Director, $fresh->getWorkRole());
        $fresh = $this->em->find(TelegramUser::class, $colleague->getId());
        self::assertTrue($hasher->isPasswordValid($fresh, self::PASSWORD));
        self::assertSame('Логіст', $fresh->getPosition());

        // Суперадмін може все, а кожна зміна — у журналі аудиту, без значень пароля.
        $this->login($this->em->find(TelegramUser::class, $boss->getId()));
        $this->send('PATCH', '/api/staff/' . $colleague->getId(), ['password' => 'reset-by-boss-1', 'role' => 'director']);
        self::assertResponseIsSuccessful();

        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT subject_id, actor_id, payload FROM plan_event WHERE type = 'staff_changed' AND subject_id IN (?, ?) ORDER BY id",
            [$worker->getId(), $colleague->getId()],
        );
        self::assertCount(3, $rows, 'посада колеги, пароль+роль людини, пароль+роль колеги від суперадміна');
        $last = json_decode($rows[2]['payload'], true);
        self::assertSame($boss->getId(), (int) $rows[2]['actor_id']);
        self::assertSame(['role', 'password'], $last['fields']);
        self::assertSame(['admin', 'director'], [$last['roleFrom'], $last['roleTo']]);
        self::assertStringNotContainsString('reset-by-boss', $rows[2]['payload']);
        $worker = json_decode($rows[1]['payload'], true);
        self::assertSame(['production', 'manager'], [$worker['roleFrom'], $worker['roleTo']]);
    }

    private function unread(int $taskId): int
    {
        foreach ($this->get('/api/tasks/board')['new'] as $item) {
            if ($item['id'] === $taskId) {
                return $item['unread'];
            }
        }

        return -1;
    }

    private function user(WorkRole $role, string $name, ?Department $department = null): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId(self::PREFIX . $name . '-' . uniqid())
            ->setFirstName(ucfirst($name))
            ->setLastName('Тестовий')
            ->setWorkRole($role)
            ->setDepartment($department)
            ->setEmail(self::PREFIX . $name . uniqid() . '@example.test');
        $user->decideAccess(AccessStatus::Approved, null);
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function login(TelegramUser $user): void
    {
        $this->client->loginUser($user);
    }

    private function upload(string $path, string $mime): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'up');
        copy($path, $copy);

        return new UploadedFile($copy, basename($path), $mime, null, true);
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
