<?php

namespace App\Tests\Tasks;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Entity\Department;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\PreviewGenerator;
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
        self::assertSame(1, $this->unread($task['id']));

        // Чужий — ні.
        $this->login($outsider);
        $this->client->request('GET', $image['urls']['download']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDangerousFilesAreNeverInline(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'HTML у вкладенні']);

        $path = tempnam(sys_get_temp_dir(), 'html');
        file_put_contents($path, '<script>alert(1)</script>');
        $this->client->request('POST', '/api/tasks/' . $task['id'] . '/messages', [], ['files' => [new UploadedFile($path, 'x.html', 'text/html', null, true)]]);
        $file = $this->json()['attachments'][0];

        self::assertSame('other', $file['kind']);
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
