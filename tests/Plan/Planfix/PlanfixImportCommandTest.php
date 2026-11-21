<?php

namespace App\Tests\Plan\Planfix;

use App\Plan\Planfix\PlanfixImportGuard;
use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Service\AttachmentStorage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * plan:planfix:* на крихітному синтетичному вивантаженні: dry-run нічого не пише в задачі,
 * запобіжник (лише справжнє середовище процесу + --confirm), задачі з оригінальними датами,
 * заглушки трьох видів, без подій журналу (отже без сповіщень); повтор не дублює, відкат
 * не чіпає чужого без явного дозволу, надгробки, relink, purge-staging.
 */
class PlanfixImportCommandTest extends KernelTestCase
{
    private const array TABLES = ['task', 'task_message', 'task_member', 'task_read', 'task_label', 'task_task_label', 'telegram_user', 'plan_event', 'notification', 'task_attachment', 'search_document'];

    private EntityManagerInterface $em;
    private Connection $db;
    private string $dir;
    private string $map;
    private int $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->em->beginTransaction();
        putenv(PlanfixImportGuard::ENV);

        $this->manager = (int) $this->db->fetchOne(
            "INSERT INTO telegram_user (id, telegram_id, first_name, last_name, language_code, created_at, supply_role, access_status, work_role, chat_id)
             VALUES (nextval('telegram_user_id_seq'), ?, 'Тест', 'Менеджер', 'uk', LOCALTIMESTAMP(0), 'manager', 'approved', 'manager', '555000') RETURNING id",
            [(string) random_int(100000000, 999999999)],
        );
        $this->dir = sys_get_temp_dir() . '/pf-test-' . uniqid('', true);
        mkdir($this->dir);
        $this->map = $this->dir . '/map.json';
        file_put_contents($this->map, json_encode(['101' => $this->manager]));
        $this->export();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        putenv(PlanfixImportGuard::ENV);
        unset($_SERVER[PlanfixImportGuard::ENV], $_ENV[PlanfixImportGuard::ENV]);

        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }

        rmdir($this->dir);
        parent::tearDown();
    }

    public function testDryRunApplyIdempotencyAndRollback(): void
    {
        $before = $this->counts();

        // Dry-run: лише staging; приховану дію пропущено й пораховано.
        $out = $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 't1']);
        self::assertStringContainsString('dry-run', $out);
        self::assertMatchesRegularExpression('/Приховані \(hidden\) дії\s+1, пропущено 1/u', $out);
        self::assertSame($before, $this->counts(), 'dry-run не чіпає задачі');
        self::assertSame(4, (int) $this->db->fetchOne("SELECT COUNT(*) FROM planfix_import_task WHERE batch_id = 't1'"));

        // Запобіжник: без змінної процесу — відмова; значення з $_SERVER/$_ENV (як від Dotenv) не діє;
        // зі змінною, але без --confirm=<база> — теж відмова.
        $this->console('plan:planfix:import', $this->apply('t1'), Command::FAILURE);
        $_SERVER[PlanfixImportGuard::ENV] = $_ENV[PlanfixImportGuard::ENV] = '1';
        $this->console('plan:planfix:import', $this->apply('t1'), Command::FAILURE);
        putenv(PlanfixImportGuard::ENV . '=1');
        $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 't1', '--apply' => true], Command::FAILURE);
        $this->console('plan:planfix:import', [...$this->apply('t1'), '--confirm' => 'not-this-db'], Command::FAILURE);
        self::assertSame($before, $this->counts());

        $this->console('plan:planfix:import', $this->apply('t1'));

        $after = $this->counts();
        self::assertSame($before['task'] + 4, $after['task']);
        self::assertSame($before['plan_event'], $after['plan_event'], 'жодної події журналу → жодних Telegram/push/Хроніки');
        self::assertSame($before['notification'], $after['notification']);
        self::assertSame($before['task_attachment'], $after['task_attachment'], 'файлів немає → Диск не синхронізує');
        self::assertSame($before['search_document'], $after['search_document']);

        $deal = $this->task(11);
        self::assertSame('Тестова угода', $deal['title']);
        self::assertSame('in_work', $deal['status']);
        self::assertSame('2024-01-05 09:30:00', $deal['created_at']);
        self::assertSame('2024-01-20 18:00:00', $deal['due_at']);
        self::assertSame('ТОВ Синтетика', $deal['client']);
        self::assertSame($this->manager, (int) $deal['creator_id']);
        self::assertStringStartsWith('Із Planfix № 501 · угода', $deal['description']);
        self::assertStringContainsString("Поля угоди:\nКомпанія: ТОВ Синтетика\nОплата: Не оплачено", $deal['description']);
        self::assertEqualsCanonicalizing(['Planfix'], $this->db->fetchFirstColumn(
            'SELECT l.name FROM task_task_label x JOIN task_label l ON l.id = x.label_id WHERE x.task_id = ?',
            [$deal['id']],
        ));

        // Чат: оригінальні дати, хронологія, автори; токен файлу вирізано; прихованої дії немає.
        $messages = $this->messages((int) $deal['id']);
        self::assertSame(['event', 'comment', 'event', 'comment'], array_column($messages, 'kind'));
        self::assertSame(['2024-01-05 09:30:00', '2024-01-05 10:00:00', '2024-01-06 11:00:00', '2024-01-07 12:00:00'], array_column($messages, 'created_at'));
        self::assertSame(['Колишній співробітник', '#202'], [$messages[1]['first_name'], $messages[1]['last_name']]);
        self::assertStringNotContainsString('planfixauth', $messages[3]['text']);
        self::assertStringContainsString('[файл з Planfix не перенесено]', $messages[3]['text']);
        self::assertNotContains('Прихована примітка', array_column($messages, 'text'));

        // Заглушки трьох видів: колишній, діючий у Planfix без облікового запису, контакт з сайту.
        self::assertSame('Співробітник Planfix', $this->ghost(606)['first_name']);
        self::assertSame('Контакт з сайту', $this->ghost(505)['first_name']);
        $ghost = $this->ghost(202);
        self::assertSame('rejected', $ghost['access_status']);
        self::assertNotNull($ghost['archived_at']);
        self::assertNull($ghost['chat_id']);
        self::assertNull($ghost['password']);

        // Живу людину не засипаємо «+N».
        self::assertSame($this->lastMessage((int) $deal['id']), $this->readUpTo((int) $deal['id']));

        // closed_at — за останньою зміною статусу, хоч сама подія в чат не йде.
        self::assertSame('2023-03-02 08:00:00', $this->task(12)['closed_at']);
        $sub = $this->task(13);
        self::assertSame('done', $sub['status']);
        self::assertSame('2023-03-05 10:00:00', $sub['closed_at']);
        self::assertStringContainsString("Підзадача Planfix № 502\n\nПоля:\nПріоритет: Low", $sub['description'], 'у задачі — «Поля:», не «Поля угоди:»');

        // Повтор того ж — нічого не дублює.
        $this->console('plan:planfix:import', $this->apply('t2'));
        self::assertSame($after, $this->counts());

        // Нове вивантаження з однією новою дією — додається лише вона; прочитано й її.
        $this->export(extraComment: true);
        $this->console('plan:planfix:import', $this->apply('t3'));
        $more = $this->counts();
        self::assertSame($after['task'], $more['task']);
        self::assertSame($after['task_message'] + 1, $more['task_message']);
        self::assertSame('2024-02-01 09:00:00', $this->db->fetchOne('SELECT last_activity_at FROM task WHERE id = ?', [$deal['id']]));
        self::assertSame($this->lastMessage((int) $deal['id']), $this->readUpTo((int) $deal['id']), 'task_read підтягнуто до нового повідомлення');

        // Відкат t3 — лише його повідомлення.
        $this->console('plan:planfix:rollback', ['batch' => 't3', '--confirm' => $this->db->getDatabase()]);
        self::assertSame($after['task_message'], $this->counts()['task_message']);

        // Повний відкат t1 у чистій базі — усе, разом із заглушками й мітками.
        $this->console('plan:planfix:rollback', ['batch' => 't1', '--dry-run' => true]);
        self::assertSame($after['task'], $this->counts()['task']);
        $this->console('plan:planfix:rollback', ['batch' => 't1', '--confirm' => $this->db->getDatabase(), '--purge-staging' => true]);
        $final = $this->counts();
        $final['task_read'] = $before['task_read'];
        self::assertSame($before, $final);
        self::assertFalse($this->db->fetchOne("SELECT 1 FROM telegram_user WHERE telegram_id LIKE 'planfix-%'"));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM planfix_import_task WHERE batch_id = 't1'"));
        self::assertNotFalse($this->db->fetchOne("SELECT 1 FROM planfix_import_batch WHERE id = 't1'"), 'партія лишається');
    }

    public function testRollbackDoesNotSilentlyDeleteUserContent(): void
    {
        putenv(PlanfixImportGuard::ENV . '=1');
        $before = $this->counts();
        $this->console('plan:planfix:import', $this->apply('u1'));
        $deal = (int) $this->task(11)['id'];
        $old = (int) $this->task(12)['id'];

        // Після імпорту люди працюють у Плані: коментар в угоді; подія й сповіщення по задачі 12.
        $this->db->executeStatement("INSERT INTO task_message (id, task_id, author_id, kind, text, mentions, created_at) VALUES (nextval('task_message_id_seq'), ?, ?, 'comment', 'Живий коментар', '[]', LOCALTIMESTAMP(0))", [$deal, $this->manager]);
        $event = (int) $this->db->fetchOne(
            "INSERT INTO plan_event (id, type, subject_type, subject_id, occurred_at, payload, channel, actor_id) VALUES (nextval('plan_event_id_seq'), 'subject_viewed', 'task', ?, LOCALTIMESTAMP(0), '{}', 'web', ?) RETURNING id",
            [$old, $this->manager],
        );
        $this->db->executeStatement('INSERT INTO notification (id, created_at, user_id, event_id) VALUES (nextval(\'notification_id_seq\'), LOCALTIMESTAMP(0), ?, ?)', [$this->manager, $event]);
        $this->db->executeStatement("INSERT INTO plan_read (user_id, subject_type, subject_id, last_event_id, read_at) VALUES (?, 'task', ?, ?, LOCALTIMESTAMP(0))", [$this->manager, $old, $event]);
        $snapshot = $this->counts();

        // За замовчуванням — відмова, нічого не видалено, попередження до видалення.
        $out = $this->console('plan:planfix:rollback', ['batch' => 'u1', '--confirm' => $this->db->getDatabase()], Command::FAILURE);
        self::assertStringContainsString('Нічого не видалено', $out);
        self::assertSame($snapshot, $this->counts());

        // --keep-touched: угода лишається з живим коментарем, решта задач — геть разом зі своїми подіями.
        $this->console('plan:planfix:rollback', ['batch' => 'u1', '--confirm' => $this->db->getDatabase(), '--keep-touched' => true]);
        self::assertSame(['Живий коментар'], $this->db->fetchFirstColumn('SELECT text FROM task_message WHERE task_id = ?', [$deal]));
        self::assertSame([$this->manager], array_map('intval', $this->db->fetchFirstColumn('SELECT user_id FROM task_member WHERE task_id = ?', [$deal])));
        self::assertFalse($this->db->fetchOne('SELECT 1 FROM task WHERE id = ?', [$old]));
        self::assertFalse($this->db->fetchOne('SELECT 1 FROM plan_event WHERE id = ?', [$event]));
        self::assertSame($before['notification'], $this->counts()['notification']);
        self::assertFalse($this->db->fetchOne("SELECT 1 FROM plan_read WHERE subject_type = 'task' AND subject_id = ?", [$old]));
        self::assertSame($before['task'] + 1, $this->counts()['task']);

        // --force-delete-user-content прибирає й лишене.
        $this->console('plan:planfix:rollback', ['batch' => 'u1', '--confirm' => $this->db->getDatabase(), '--force-delete-user-content' => true]);
        self::assertFalse($this->db->fetchOne('SELECT 1 FROM task WHERE id = ?', [$deal]));
    }

    public function testDeletedByHandStaysDeletedOnReimport(): void
    {
        putenv(PlanfixImportGuard::ENV . '=1');
        $this->console('plan:planfix:import', $this->apply('d1'));
        $this->db->executeStatement('DELETE FROM task WHERE id = ?', [$this->task(12)['id']]);
        $tasks = $this->counts()['task'];

        $out = $this->console('plan:planfix:import', $this->apply('d2'));
        self::assertMatchesRegularExpression('/tasks_tombstoned\s+1/', $out);
        self::assertSame($tasks, $this->counts()['task'], 'видалена руками задача не повертається');
        self::assertNotFalse($this->db->fetchOne("SELECT deleted_at FROM planfix_import_map WHERE planfix_type = 'task' AND planfix_id = 12"));
    }

    public function testRelinkMergesPlaceholderIntoRealPerson(): void
    {
        putenv(PlanfixImportGuard::ENV . '=1');
        $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 'r1', '--apply' => true, '--confirm' => $this->db->getDatabase()]);
        $deal = (int) $this->task(11)['id'];
        self::assertSame((int) $this->ghost(101)['id'], (int) $this->task(11)['creator_id']);

        $this->console('plan:planfix:relink', ['planfix-id' => '101', 'user-id' => (string) $this->manager], Command::FAILURE);
        $this->console('plan:planfix:relink', ['planfix-id' => '101', 'user-id' => (string) $this->manager, '--confirm' => $this->db->getDatabase()]);

        self::assertSame($this->manager, (int) $this->task(11)['creator_id']);
        self::assertFalse($this->db->fetchOne("SELECT 1 FROM telegram_user WHERE telegram_id = 'planfix-101'"));
        self::assertSame($this->lastMessage($deal), $this->readUpTo($deal));

        // Наступний імпорт пише на живу людину, нової заглушки не заводить.
        $this->export(extraComment: true);
        $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 'r2', '--apply' => true, '--confirm' => $this->db->getDatabase()]);
        self::assertFalse($this->db->fetchOne("SELECT 1 FROM telegram_user WHERE telegram_id = 'planfix-101'"));
        self::assertSame($this->manager, (int) $this->db->fetchOne("SELECT author_id FROM task_message WHERE text = 'Новий коментар'"));
    }

    public function testPurgeStagingKeepsMapAndDryRunReusesStaging(): void
    {
        // Dry-run без --batch двічі — повних копій не накопичується.
        $this->console('plan:planfix:import', ['dir' => $this->dir]);
        $this->console('plan:planfix:import', ['dir' => $this->dir]);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(DISTINCT batch_id) FROM planfix_import_task'));

        putenv(PlanfixImportGuard::ENV . '=1');
        $this->console('plan:planfix:import', $this->apply('p1'));
        $map = (int) $this->db->fetchOne("SELECT COUNT(*) FROM planfix_import_map WHERE batch_id = 'p1'");
        self::assertGreaterThan(0, $map);

        $this->console('plan:planfix:purge-staging', ['batch' => 'p1']);
        foreach (['planfix_import_task', 'planfix_import_action', 'planfix_import_user'] as $table) {
            self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM {$table} WHERE batch_id = 'p1'"), $table);
        }

        self::assertSame($map, (int) $this->db->fetchOne("SELECT COUNT(*) FROM planfix_import_map WHERE batch_id = 'p1'"));
        self::assertNotFalse($this->db->fetchOne("SELECT 1 FROM planfix_import_batch WHERE id = 'p1'"));

        // Карта на місці → повторний імпорт нічого не дублює.
        $tasks = $this->counts()['task'];
        $this->console('plan:planfix:import', $this->apply('p2'));
        self::assertSame($tasks, $this->counts()['task']);
    }

    public function testHiddenOnlyOnRequest(): void
    {
        putenv(PlanfixImportGuard::ENV . '=1');
        $this->console('plan:planfix:import', [...$this->apply('h1'), '--include-hidden' => true]);
        self::assertContains('Прихована примітка', array_column($this->messages((int) $this->task(11)['id']), 'text'));
    }

    public function testFilesLandInTheirMessagesAndRollbackRemovesThem(): void
    {
        $this->export(fileAction: true);
        $put = function (string $name, string $body): string {
            file_put_contents($this->dir . '/' . $name, $body);

            return $this->dir . '/' . $name;
        };
        $pdf = $put('f-pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $png = $put('f-png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $txt = $put('f-txt', "Специфікація\n");
        $htm = $put('f-htm', '<html><body><script>x()</script></body></html>');
        $line = static fn (int $id, string $name, int $task, ?int $comment, string $path, ?string $error = null): string => json_encode(
            ['file_id' => $id, 'name' => $name, 'task' => $task, 'comment' => $comment, 'size' => filesize($path), 'sha256' => hash_file('sha256', $path), 'local_path' => $path, 'error' => $error],
            JSON_UNESCAPED_UNICODE,
        );
        file_put_contents($this->dir . '/manifest.jsonl', implode("\n", [
            $line(9001, 'Рахунок.pdf', 501, 5, $pdf),        // дія з текстом і позначкою «файл не перенесено»
            $line(9002, 'фото.png', 501, 20, $png),           // порожня дія — стане повідомленням із файлом
            $line(9003, 'ТЗ.txt', 501, null, $txt),           // файл самої задачі → «задачу створено»
            $line(9004, 'креслення.pdf', 501, 1, $pdf),       // додано при створенні → «задачу створено»
            $line(9005, 'сторінка.htm', 501, 2, $htm),        // такий тип не приймаємо
            $line(9006, 'новий.pdf', 501, 777, $pdf),         // дія після вивантаження
            $line(9007, 'прихований.pdf', 501, 10, $pdf),     // прихована дія
            $line(9008, 'зламаний.pdf', 501, 2, $pdf, 'HTTP 404'),
        ]) . "\n");
        $before = $this->counts();

        $out = $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 'f1', '--files' => $this->dir]);
        self::assertMatchesRegularExpression('/Беремо файлів\s+4 \(/u', $out);
        self::assertMatchesRegularExpression('/Такий тип не приймаємо\s+htm 1/u', $out);
        self::assertMatchesRegularExpression('/Додані в Planfix після вивантаження.*\s+1/u', $out);
        self::assertMatchesRegularExpression('/У прихованих діях.*\s+1/u', $out);
        self::assertMatchesRegularExpression('/Не завантажені \/ порожні\s+1/u', $out);
        self::assertSame($before, $this->counts(), 'dry-run не чіпає задачі й файли');

        putenv(PlanfixImportGuard::ENV . '=1');
        $this->console('plan:planfix:import', [...$this->apply('f1'), '--files' => $this->dir]);

        $after = $this->counts();
        self::assertSame($before['task_attachment'] + 4, $after['task_attachment']);
        self::assertSame($before['plan_event'], $after['plan_event'], 'файли теж без подій журналу');

        $deal = $this->task(11);
        $files = $this->db->fetchAllAssociative(
            'SELECT a.id, a.original_name, a.mime, a.kind, a.preview_state, a.drive_state, a.path, a.created_at, a.uploaded_by_id, m.kind AS message_kind, m.text, m.event_type
               FROM task_attachment a JOIN task_message m ON m.id = a.message_id WHERE m.task_id = ? ORDER BY a.id',
            [$deal['id']],
        );
        self::assertSame(['ТЗ.txt', 'креслення.pdf', 'фото.png', 'Рахунок.pdf'], array_column($files, 'original_name'), 'у порядку дій');
        self::assertSame(['created', 'created'], [$files[0]['event_type'], $files[1]['event_type']], 'файли задачі — у «задачу створено»');
        self::assertSame(['application/pdf', 'pdf', 'pending'], [$files[3]['mime'], $files[3]['kind'], $files[3]['preview_state']]);
        self::assertStringNotContainsString('[файл з Planfix не перенесено]', $files[3]['text'], 'файл поруч — позначку прибрано');
        self::assertSame(['comment', '', '2024-01-06 12:00:00'], [$files[2]['message_kind'], $files[2]['text'], $files[2]['created_at']]);
        self::assertSame(['skipped'], array_values(array_unique(array_column($files, 'drive_state'))), 'на Google Диск не копіюємо');
        self::assertStringStartsWith('files/2024/01/', $files[3]['path']);

        $storage = self::getContainer()->get(AttachmentStorage::class);
        $paths = array_map(fn (array $f): string => $storage->original($this->em->find(TaskAttachment::class, $f['id'])), $files);

        foreach ($paths as $path) {
            self::assertFileExists($path);
        }

        self::assertSame(hash_file('sha256', $pdf), hash_file('sha256', $paths[3]));

        // Хронологія: порожня дія з файлом стоїть між своїми сусідами за часом.
        $times = array_column($this->messages((int) $deal['id']), 'created_at');
        $sorted = $times;
        sort($sorted);
        self::assertSame($sorted, $times);
        self::assertContains('2024-01-06 12:00:00', $times);

        // Повтор не дублює ні файлів, ні копій.
        $this->console('plan:planfix:import', [...$this->apply('f2'), '--files' => $this->dir]);
        self::assertSame($after, $this->counts());

        // Відкат: файли партії — геть із бази й з диска.
        $rollback = $this->console('plan:planfix:rollback', ['batch' => 'f1', '--confirm' => $this->db->getDatabase()]);
        self::assertMatchesRegularExpression('/files_removed\s+4/', $rollback);
        self::assertSame($before['task_attachment'], $this->counts()['task_attachment']);

        foreach ($paths as $path) {
            self::assertFileDoesNotExist($path);
        }
    }

    public function testSinceKeepsOpenAndRecentlyActive(): void
    {
        $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 'f1', '--only' => 'deal']);
        self::assertSame(['501'], $this->staged('f1'));

        // 502 закрита й давно без дій — геть; 503 закрита, але з дією у 2024; 501 і 504 відкриті.
        $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 'f2', '--since' => '2024-01-01']);
        self::assertSame(['501', '503', '504'], $this->staged('f2'));
    }

    /** @return array<string, mixed> */
    private function apply(string $batch): array
    {
        return ['dir' => $this->dir, '--batch' => $batch, '--apply' => true, '--user-map' => $this->map, '--confirm' => $this->db->getDatabase()];
    }

    /** @param array<string, mixed> $input */
    private function console(string $name, array $input, int $expected = Command::SUCCESS): string
    {
        $tester = new CommandTester(new Application(self::$kernel)->find($name));
        $code = $tester->execute($input);
        self::assertSame($expected, $code, $tester->getDisplay());

        return $tester->getDisplay();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $out = [];

        foreach (self::TABLES as $t) {
            $out[$t] = (int) $this->db->fetchOne("SELECT COUNT(*) FROM {$t}");
        }

        return $out;
    }

    /** @return list<string> */
    private function staged(string $batch): array
    {
        return array_map('strval', $this->db->fetchFirstColumn('SELECT number FROM planfix_import_task WHERE batch_id = ? ORDER BY number', [$batch]));
    }

    /** @return array<string, mixed> */
    private function task(int $planfixId): array
    {
        $row = $this->db->fetchAssociative(
            "SELECT t.* FROM task t JOIN planfix_import_map x ON x.plan_table = 'task' AND x.plan_id = t.id AND x.planfix_type = 'task' WHERE x.planfix_id = ?",
            [$planfixId],
        );
        self::assertNotFalse($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function ghost(int $planfixId): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM telegram_user WHERE telegram_id = ?', ['planfix-' . $planfixId]);
        self::assertNotFalse($row);

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function messages(int $taskId): array
    {
        return $this->db->fetchAllAssociative('SELECT m.kind, m.text, m.created_at, m.author_id, u.first_name, u.last_name FROM task_message m LEFT JOIN telegram_user u ON u.id = m.author_id WHERE m.task_id = ? ORDER BY m.id', [$taskId]);
    }

    private function lastMessage(int $taskId): int
    {
        return (int) $this->db->fetchOne('SELECT MAX(id) FROM task_message WHERE task_id = ?', [$taskId]);
    }

    private function readUpTo(int $taskId): int
    {
        return (int) $this->db->fetchOne('SELECT last_message_id FROM task_read WHERE task_id = ? AND user_id = ?', [$taskId, $this->manager]);
    }

    /**
     * Синтетичне вивантаження у форматі Planfix: угода, 2 задачі, заявка з сайту.
     * Люди: 101 і 606 — діючі (101 зіставлено в тестах), 202 і 303 — колишні, 505 — контакт.
     */
    private function export(bool $extraComment = false, bool $fileAction = false): void
    {
        $task = static fn (array $t): array => $t + ['description' => '', 'importance' => 5, 'workers' => [], 'hasEndDate' => false, 'hasStartDate' => false, 'checkResult' => true];
        $tasks = [
            $task(['id' => 11, 'number' => 501, 'title' => 'Тестова угода', 'description' => '<p>Опис угоди</p>', 'status' => 'Ціноутворення', 'importance' => 10,
                'owner' => ['id' => 101], 'workers' => ['users' => [['id' => 202], ['id' => 606]], 'groups' => [['id' => -1, 'name' => 'Неіснуючий відділ']]],
                'createDate' => '05-01-2024 09:30', 'hasEndDate' => true, 'endTime' => '20-01-2024 18:00',
                'project' => ['id' => 7], 'customData' => ['57554' => 'Не оплачено', '57592' => 'ТОВ Синтетика', '57552' => '0']]),
            $task(['id' => 12, 'number' => 502, 'title' => 'Скасована задача', 'status' => 'Отмененная', 'owner' => ['id' => 303], 'createDate' => '01-03-2023 08:00']),
            $task(['id' => 13, 'number' => 503, 'title' => 'Підзадача', 'status' => 'Завершенная', 'parent' => ['id' => 12], 'owner' => ['id' => 101],
                'createDate' => '02-03-2023 08:00', 'customData' => ['57584' => 'Low']]),
            $task(['id' => 14, 'number' => 504, 'title' => 'Заявка з сайту', 'status' => 'Новая', 'owner' => ['id' => 505], 'createDate' => '03-03-2023 08:00']),
        ];
        $action = static fn (int $id, int $task, int $owner, string $type, string $at, string $text, bool $hidden = false): array => ['id' => $id, 'task' => ['id' => $task], 'owner' => ['id' => $owner], 'type' => $type, 'dateTime' => $at, 'hidden' => $hidden, 'description' => $text];
        $actions = [
            $action(1, 11, 101, 'TaskCreated', '05-01-2024 09:30', 'Завдання створене'),
            $action(2, 11, 202, 'Comment', '05-01-2024 10:00', '<p>Перший коментар</p>'),
            $action(3, 11, 101, 'TaskOverdued', '05-01-2024 11:00', '{event21}'),
            $action(4, 11, 101, 'WorkerEmployed', '06-01-2024 11:00', "{event7}: <a href='/user/2' >Тест Колишній</a>"),
            $action(5, 11, 101, 'Action', '07-01-2024 12:00', '<p>Файл <img src="https://demo.planfix.com/?action=getfile&amp;uniqueid=9&amp;planfixauth=TOKEN"></p>'),
            $action(10, 11, 101, 'Action', '07-01-2024 13:00', 'Прихована примітка', hidden: true),
            $action(9, 12, 101, 'Action', '02-03-2023 07:00', 'Нагадування робота'),
            $action(6, 12, 303, 'Comment', '02-03-2023 08:00', 'Скасовуємо'),
            $action(11, 13, 101, 'StatusChanged', '05-03-2023 10:00', ''),
            $action(12, 13, 101, 'TaskAccepted', '01-03-2024 10:00', 'Завдання прийняте'),
            $action(13, 14, 505, 'TaskCreated', '03-03-2023 08:00', 'Завдання створене'),
            $action(7, 999, 101, 'TaskCreated', '02-03-2023 08:00', 'Контакт створений'),
        ];

        if ($extraComment) {
            $actions[] = $action(8, 11, 101, 'Comment', '01-02-2024 09:00', 'Новий коментар');
        }

        if ($fileAction) {
            // Порожня дія лише з файлом — без --files її пропускають.
            $actions[] = $action(20, 11, 202, 'Action', '06-01-2024 12:00', '');
        }

        $files = [
            'tasks.json' => $tasks,
            'actions.json' => $actions,
            'users.json' => [
                ['id' => 101, 'name' => 'Тест', 'lastName' => 'Діючий', 'midName' => '', 'postId' => 0],
                ['id' => 606, 'name' => 'Тест', 'lastName' => 'Без-Плану', 'midName' => '', 'postId' => 0],
            ],
            'projects.json' => [['id' => 7, 'title' => 'Тестовий проєкт', 'number' => 1, 'status' => 'Активный']],
        ];

        foreach ($files as $name => $data) {
            file_put_contents($this->dir . '/' . $name, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
    }
}
