<?php

namespace App\Tests\Tasks;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Access\RoleGrants;
use App\Plan\Access\RolePermission;
use App\Tasks\Entity\Task;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\AttachmentStorage;
use App\Tasks\Service\TaskService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Кошик задач (Олександр 19.11): «Видалити» — адміністратор, директор, системний адміністратор
 * (право tasks.delete у «Ролі й доступ»), лише видимі задачі. Видалена задача зникає з дошки,
 * списків, Огляду, пошуку й Хроніки, 30 днів її можна відновити, далі tasks:trash:purge
 * видаляє її остаточно разом із файлами.
 */
class TaskTrashTest extends WebTestCase
{
    private const string PREFIX = 'trash-test-';

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
            $tasks = $conn->fetchFirstColumn("SELECT id FROM task WHERE creator_id IN ($list)");
            if ($tasks !== []) {
                $taskList = implode(',', array_map('intval', $tasks));
                $conn->executeStatement("DELETE FROM search_document WHERE subject_type = 'task' AND subject_id IN ($taskList)");
                $conn->executeStatement("DELETE FROM plan_subject_activity WHERE subject_type = 'task' AND subject_id IN ($taskList)");
                $conn->executeStatement("DELETE FROM plan_event WHERE subject_type = 'task' AND subject_id IN ($taskList)");
            }
            $conn->executeStatement("DELETE FROM task WHERE creator_id IN ($list)");
            $conn->executeStatement("DELETE FROM plan_event WHERE actor_id IN ($list) OR (subject_type = 'user' AND subject_id IN ($list))");
            $conn->executeStatement("DELETE FROM search_document WHERE subject_type = 'user' AND subject_id IN ($list)");
            $conn->executeStatement("DELETE FROM telegram_user WHERE id IN ($list)");
        }
        $conn->executeStatement("DELETE FROM plan_role_permission WHERE permission = 'tasks.delete'");
        RoleGrants::reset();

        parent::tearDown();
    }

    public function testDefaultsWhoMayDelete(): void
    {
        foreach (WorkRole::cases() as $role) {
            self::assertSame(
                in_array($role, [WorkRole::Admin, WorkRole::Director, WorkRole::SysAdmin], true),
                RolePermission::TasksDelete->byDefault($role),
                $role->value,
            );
        }
    }

    public function testDeleteHidesEverywhereAndRestoreBringsBack(): void
    {
        $director = $this->user(WorkRole::Director, 'dir');
        $manager = $this->user(WorkRole::Manager, 'man');
        $title = 'Кошиктест ' . bin2hex(random_bytes(4));
        $task = $this->task($manager, $title, [$manager]);
        $id = (int) $task->getId();

        $this->client->loginUser($director);
        $places = ['/api/tasks/board', '/api/tasks/list?f=open', '/api/tasks/dashboard', '/api/plan/search?q=' . urlencode($title), '/api/plan/chronicle'];
        foreach ($places as $url) {
            self::assertTrue(self::mentions($this->raw('GET', $url), $id, $title), 'До видалення задача є: ' . $url);
        }
        self::assertTrue($this->json('GET', '/api/tasks/' . $id)['can']['delete']);
        self::assertTrue($this->json('GET', '/api/tasks/meta')['me']['can']['deleteTasks']);

        // Менеджер (автор) права не має: кнопки нема, запит відхилено.
        $this->client->loginUser($manager);
        self::assertFalse($this->json('GET', '/api/tasks/' . $id)['can']['delete']);
        $this->client->request('DELETE', '/api/tasks/' . $id);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/api/tasks/trash');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($director);
        $deleted = $this->json('DELETE', '/api/tasks/' . $id);
        self::assertResponseIsSuccessful();
        self::assertSame($id, $deleted['id']);
        self::assertSame($director->getId(), $deleted['deletedBy']['id']);
        self::assertNotNull($deleted['purgeAt']);
        $this->client->request('DELETE', '/api/tasks/' . $id);
        self::assertResponseStatusCodeSame(422, 'Удруге — «вже в кошику»');

        foreach ([...$places, '/api/plan/overview'] as $url) {
            self::assertFalse(self::mentions($this->raw('GET', $url), $id, $title), $url);
        }
        $this->client->request('GET', '/api/tasks/' . $id);
        self::assertResponseStatusCodeSame(403, 'Картка видаленої задачі закрита');
        $this->client->request('POST', '/api/tasks/' . $id . '/messages', ['text' => 'ще щось']);
        self::assertResponseStatusCodeSame(403);

        // Учасник теж не бачить її ніде.
        $this->client->loginUser($manager);
        self::assertStringNotContainsString($title, $this->raw('GET', '/api/tasks/board'));

        $this->client->loginUser($director);
        $trash = $this->json('GET', '/api/tasks/trash');
        self::assertSame(Task::TRASH_DAYS, $trash['days']);
        self::assertContains($id, array_column($trash['items'], 'id'));

        $restored = $this->json('POST', '/api/tasks/' . $id . '/restore');
        self::assertResponseIsSuccessful();
        self::assertSame($id, $restored['id']);
        self::assertStringContainsString($title, $this->raw('GET', '/api/tasks/board'));
        self::assertTrue(self::mentions($this->raw('GET', '/api/plan/search?q=' . urlencode($title)), $id, $title), 'Після відновлення — знову в пошуку');
        self::assertNotContains($id, array_column($this->json('GET', '/api/tasks/trash')['items'], 'id'));

        $types = $this->em->getConnection()->fetchFirstColumn("SELECT type FROM plan_event WHERE subject_type = 'task' AND subject_id = ? AND type IN ('subject_deleted', 'subject_restored') ORDER BY id", [$id]);
        self::assertSame(['subject_deleted', 'subject_restored'], $types, 'Обидві дії — у журналі аудиту');
    }

    public function testSysAdminDeletesOnlyTasksHeSees(): void
    {
        $sys = $this->user(WorkRole::SysAdmin, 'sys');
        $author = $this->user(WorkRole::Manager, 'auth');
        $foreign = $this->task($author, 'Чужа ' . uniqid(), [$author]);
        $own = $this->task($author, 'Спільна ' . uniqid(), [$author, $sys]);

        $this->client->loginUser($sys);
        $this->client->request('DELETE', '/api/tasks/' . $foreign->getId());
        self::assertResponseStatusCodeSame(403, 'Задачу, де він не учасник, системний адміністратор не бачить — і не видаляє');

        $this->json('DELETE', '/api/tasks/' . $own->getId());
        self::assertResponseIsSuccessful();
        self::assertSame([(int) $own->getId()], array_column($this->json('GET', '/api/tasks/trash')['items'], 'id'));
    }

    public function testPermissionIsConfigurableInRoles(): void
    {
        $admin = $this->user(WorkRole::Admin, 'adm');
        $manager = $this->user(WorkRole::Manager, 'man2');
        $task = $this->task($manager, 'Менеджерська ' . uniqid(), [$manager]);

        $this->client->loginUser($admin);
        $this->json('POST', '/api/plan/roles/permission', ['role' => 'manager', 'permission' => 'tasks.delete', 'allowed' => true]);
        self::assertResponseIsSuccessful();
        RoleGrants::reset();

        $this->client->loginUser($manager);
        $this->json('DELETE', '/api/tasks/' . $task->getId());
        self::assertResponseIsSuccessful();
    }

    public function testPurgeAfterThirtyDaysRemovesTaskAndFiles(): void
    {
        $director = $this->user(WorkRole::Director, 'dir2');
        $old = $this->task($director, 'Стара ' . uniqid(), [$director]);
        $fresh = $this->task($director, 'Свіжа ' . uniqid(), [$director]);
        $alive = $this->task($director, 'Жива ' . uniqid(), [$director]);

        $png = tempnam(sys_get_temp_dir(), 'tr');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $message = self::getContainer()->get(TaskService::class)->post($old, $director, 'файл', [new UploadedFile($png, 'plan.png', 'image/png', null, true)]);
        $attachment = $message->getAttachments()->first();
        self::assertNotFalse($attachment);
        $file = self::getContainer()->get(AttachmentStorage::class)->original($attachment);
        self::assertFileExists($file);

        $service = self::getContainer()->get(TaskService::class);
        $service->delete($old, $director);
        $service->delete($fresh, $director);
        $this->em->getConnection()->executeStatement("UPDATE task SET deleted_at = NOW() - INTERVAL '31 days' WHERE id = ?", [$old->getId()]);

        $tester = new CommandTester(new Application(self::$kernel)->find('tasks:trash:purge'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('[dry-run] Буде видалено 1', $tester->getDisplay());
        self::assertFileExists($file, 'dry-run нічого не видаляє');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $conn = $this->em->getConnection();
        self::assertFalse($conn->fetchOne('SELECT id FROM task WHERE id = ?', [$old->getId()]), 'Через 30 днів — остаточно');
        self::assertFileDoesNotExist($file, 'Файл задачі видалено з диска');
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM task_attachment WHERE id = ?', [$attachment->getId()]));
        self::assertNotFalse($conn->fetchOne('SELECT id FROM task WHERE id = ?', [$fresh->getId()]), 'Свіжа в кошику — лишається');
        self::assertNotFalse($conn->fetchOne('SELECT id FROM task WHERE id = ? AND deleted_at IS NULL', [$alive->getId()]));
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM plan_event WHERE type = 'subject_deleted' AND subject_id = ? AND payload->>'purged' = 'true'", [$old->getId()]));
    }

    /** @param list<TelegramUser> $members */
    private function task(TelegramUser $author, string $title, array $members): Task
    {
        return self::getContainer()->get(TaskService::class)->create($author, [
            'title' => $title,
            'memberIds' => array_map(static fn (TelegramUser $u) => (int) $u->getId(), $members),
        ]);
    }

    private function user(WorkRole $role, string $name): TelegramUser
    {
        $user = new TelegramUser()->setPlanFullAccess(true)
            ->setTelegramId(self::PREFIX . $name . '-' . uniqid())
            ->setFirstName('Кошик' . ucfirst($name))
            ->setLastName('Тестовий')
            ->setWorkRole($role)
            ->setEmail(self::PREFIX . $name . uniqid() . '@example.test');
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Відповідь як текст без \uXXXX — щоб шукати назву задачі (пошук ріже назву на підсвічені шматки — тому й ключ task-N). */
    private function raw(string $method, string $url): string
    {
        $this->client->request($method, $url);
        self::assertResponseIsSuccessful($url);

        return (string) json_encode(json_decode((string) $this->client->getResponse()->getContent(), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function mentions(string $content, int $id, string $title): bool
    {
        // Відповідь пошуку повторює сам запит («q») — там назва є завжди; рахуються лише знахідки.
        $content = (string) preg_replace('/"q":"[^"]*"/u', '', $content);

        return str_contains($content, $title) || str_contains($content, '"task-' . $id . '"') || str_contains($content, '/crm/tasks/search/' . $id);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function json(string $method, string $url, ?array $body = null): array
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: $body === null ? null : (string) json_encode($body));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
