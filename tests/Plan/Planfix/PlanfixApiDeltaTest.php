<?php

namespace App\Tests\Plan\Planfix;

use App\Plan\Planfix\PlanfixApiDelta;
use App\Plan\Planfix\PlanfixImportGuard;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Добір із Planfix API: відповідність людей за спільними коментарями, нова задача зі стабільним
 * id і «Виконана» (--all-done), новий коментар до вже перенесеної задачі, файли; повторний добір
 * нічого не дублює.
 */
class PlanfixApiDeltaTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;
    private string $root;
    private int $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->em->beginTransaction();
        $this->manager = (int) $this->db->fetchOne(
            "INSERT INTO telegram_user (id, telegram_id, first_name, last_name, language_code, created_at, supply_role, access_status, work_role, chat_id)
             VALUES (nextval('telegram_user_id_seq'), ?, 'Тест', 'Менеджер', 'uk', LOCALTIMESTAMP(0), 'manager', 'approved', 'manager', '555001') RETURNING id",
            [(string) random_int(100000000, 999999999)],
        );
        $this->root = sys_get_temp_dir() . '/pf-delta-' . uniqid('', true);
        mkdir($this->root . '/base', 0777, true);
        $put = fn (string $name, array $data) => file_put_contents($this->root . '/base/' . $name, json_encode($data, JSON_UNESCAPED_UNICODE));
        $put('tasks.json', [['id' => 11, 'number' => 501, 'title' => 'Стара задача', 'description' => '', 'status' => 'Завершенная', 'owner' => ['id' => 101],
            'workers' => [], 'createDate' => '05-01-2024 09:30', 'importance' => 5, 'hasEndDate' => false, 'project' => ['id' => 7]]]);
        $put('actions.json', [['id' => 1, 'task' => ['id' => 11], 'owner' => ['id' => 101], 'type' => 'Comment', 'dateTime' => '05-01-2024 10:00', 'hidden' => false, 'description' => 'Старий']]);
        $put('users.json', [['id' => 101, 'name' => 'Тест', 'lastName' => 'Діючий', 'midName' => '', 'postId' => 0]]);
        $put('projects.json', [['id' => 7, 'title' => 'Тестовий проєкт']]);
        file_put_contents($this->root . '/map.json', json_encode(['101' => $this->manager]));
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        putenv(PlanfixImportGuard::ENV);
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function testDeltaBuildsExportShapeAndReimportDoesNotDuplicate(): void
    {
        $stats = $this->delta('d1');
        self::assertSame(1, $stats['tasks_new']);
        self::assertSame(1, $stats['tasks_touched']);
        self::assertSame(2, $stats['comments_new']);
        self::assertSame(2, $stats['files_new']);
        self::assertSame(1, $stats['authors_unknown'], 'contact:99 не впізнано — без учасника');

        $tasks = json_decode((string) file_get_contents($this->root . '/d1/tasks.json'), true);
        $new = array_values(array_filter($tasks, static fn ($t) => $t['number'] === 502))[0];
        self::assertSame(PlanfixApiDelta::NEW_TASK_BASE + 502, $new['id']);
        self::assertSame(['id' => 101], $new['owner'], 'user:7 = 101 за спільним коментарем');
        self::assertSame(['id' => 11], $new['parent']);
        self::assertSame(['id' => 7], $new['project']);
        self::assertSame('17-11-2026 10:28', $new['createDate']);

        putenv(PlanfixImportGuard::ENV . '=1');
        $this->import('d1', 'api-1');
        $taskId = (int) $this->db->fetchOne("SELECT plan_id FROM planfix_import_map WHERE planfix_type = 'task' AND planfix_id = ?", [PlanfixApiDelta::NEW_TASK_BASE + 502]);
        self::assertSame('done', $this->db->fetchOne('SELECT status FROM task WHERE id = ?', [$taskId]), '--all-done: «Новая» → виконана');
        self::assertNotNull($this->db->fetchOne('SELECT closed_at FROM task WHERE id = ?', [$taskId]));
        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM planfix_import_map WHERE batch_id = 'api-1' AND planfix_type = 'action'"));
        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM planfix_import_map WHERE batch_id = 'api-1' AND planfix_type = 'file'"));
        self::assertSame($this->manager, (int) $this->db->fetchOne("SELECT m.author_id FROM task_message m JOIN planfix_import_map x ON x.plan_id = m.id AND x.planfix_type = 'action' WHERE x.planfix_id = 601"));

        // Повторний добір (понеділок): файли вже в карті — не качаємо; імпорт нічого не створює.
        $again = $this->delta('d2');
        self::assertSame(0, $again['files_new']);
        self::assertSame(2, $again['files_known']);
        $out = $this->import('d2', 'api-2');
        self::assertMatchesRegularExpression('/tasks_created\s+0/', $out);
        self::assertMatchesRegularExpression('/messages_created\s+0/', $out);

        $this->console('plan:planfix:rollback', ['batch' => 'api-1', '--confirm' => $this->db->getDatabase()]);
    }

    /** @return array<string, int> */
    private function delta(string $dir): array
    {
        $comments = [
            ['id' => 602, 'dateTime' => $this->dt('20-11-2026', '14:10'), 'type' => 'Comment', 'task' => ['id' => 502], 'owner' => ['id' => 'user:7'], 'description' => '<p>На новій задачі</p>', 'isDeleted' => false, 'isHidden' => false, 'files' => []],
            ['id' => 601, 'dateTime' => $this->dt('19-11-2026', '09:00'), 'type' => 'Comment', 'task' => ['id' => 501], 'owner' => ['id' => 'user:7'], 'description' => '<p>Після вивантаження</p>', 'isDeleted' => false, 'isHidden' => false, 'files' => [['id' => 9001, 'name' => 'Рахунок.pdf']]],
            ['id' => 600, 'dateTime' => $this->dt('18-11-2026', '09:00'), 'type' => 'Comment', 'task' => ['id' => 501], 'owner' => ['id' => 'user:7'], 'description' => 'видалений', 'isDeleted' => true, 'isHidden' => false],
            ['id' => 1, 'dateTime' => $this->dt('05-01-2024', '10:00'), 'type' => 'Comment', 'task' => ['id' => 501], 'owner' => ['id' => 'user:7'], 'description' => 'Старий', 'isDeleted' => false, 'isHidden' => false],
        ];
        $tasks = [
            ['id' => 501, 'name' => 'Стара задача', 'status' => ['name' => 'Завершенная'], 'project' => ['id' => 5]],
            ['id' => 502, 'name' => 'Нова задача', 'description' => '<p>Опис</p>', 'status' => ['name' => 'Новая'], 'assigner' => ['id' => 'user:7'],
                'assignees' => ['users' => [['id' => 'user:7'], ['id' => 'contact:99']], 'groups' => [['name' => 'Відділ продажу']]],
                'parent' => ['id' => 501], 'project' => ['id' => 5], 'dateTime' => $this->dt('17-11-2026', '10:28'), 'priority' => 'NotUrgent', 'hasEndDate' => false],
        ];
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($comments, $tasks, $pdf): MockResponse {
            self::assertContains('Authorization: Bearer test-token', $options['headers']);
            $path = (string) parse_url($url, PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/task/list') => new MockResponse(json_encode(['result' => 'success', 'tasks' => $tasks])),
                str_ends_with($path, '/comment/list') => new MockResponse(json_encode(['result' => 'success', 'comments' => $comments])),
                str_ends_with($path, '/task/502/files') => new MockResponse(json_encode(['result' => 'success', 'files' => [['id' => 9002, 'name' => 'ТЗ.pdf'], ['id' => 9001, 'name' => 'Рахунок.pdf']]])),
                str_contains($path, '/file/') => new MockResponse($pdf),
                default => new MockResponse('{}', ['http_code' => 404]),
            };
        });

        return new PlanfixApiDelta($client, $this->db)->build($this->root . '/base', $this->root . '/' . $dir, 'test-token', 'https://pf.test/rest');
    }

    private function import(string $dir, string $batch): string
    {
        $path = $this->root . '/' . $dir;

        return $this->console('plan:planfix:import', ['dir' => $path, '--files' => $path, '--batch' => $batch, '--all-done' => true, '--apply' => true,
            '--user-map' => $this->root . '/map.json', '--confirm' => $this->db->getDatabase(), '--samples' => '0']);
    }

    /** @return array<string, string> */
    private function dt(string $date, string $time): array
    {
        return ['date' => $date, 'time' => $time, 'datetime' => 'x'];
    }

    /** @param array<string, mixed> $input */
    private function console(string $name, array $input, int $expected = Command::SUCCESS): string
    {
        $tester = new CommandTester(new Application(self::$kernel)->find($name));
        $code = $tester->execute($input);
        self::assertSame($expected, $code, $tester->getDisplay());

        return $tester->getDisplay();
    }
}
