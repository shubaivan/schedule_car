<?php

namespace App\Tests\Plan\Planfix;

use App\Plan\Planfix\PlanfixImportGuard;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * plan:planfix:import / plan:planfix:rollback на крихітному синтетичному вивантаженні:
 * dry-run нічого не пише в задачі, --apply без прапорця відмовляє, з прапорцем —
 * задачі з оригінальними датами, заглушки колишніх, без подій журналу (отже без
 * сповіщень); повтор нічого не дублює, відкат прибирає рівно своє.
 */
class PlanfixImportCommandTest extends KernelTestCase
{
    private const array TABLES = ['task', 'task_message', 'task_member', 'task_read', 'task_label', 'task_task_label', 'telegram_user', 'plan_event', 'notification', 'task_attachment', 'search_document'];

    private EntityManagerInterface $em;
    private Connection $db;
    private string $dir;
    private int $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->em->beginTransaction();
        unset($_SERVER[PlanfixImportGuard::ENV], $_ENV[PlanfixImportGuard::ENV]);
        putenv(PlanfixImportGuard::ENV);

        $this->manager = (int) $this->db->fetchOne(
            "INSERT INTO telegram_user (id, telegram_id, first_name, last_name, language_code, created_at, supply_role, access_status, work_role, chat_id)
             VALUES (nextval('telegram_user_id_seq'), ?, 'Тест', 'Менеджер', 'uk', LOCALTIMESTAMP(0), 'manager', 'approved', 'manager', '555000') RETURNING id",
            ['pf-test-' . uniqid('', true)],
        );
        $this->dir = sys_get_temp_dir() . '/pf-test-' . uniqid('', true);
        mkdir($this->dir);
        $this->export();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        unset($_SERVER[PlanfixImportGuard::ENV]);

        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }

        rmdir($this->dir);
        parent::tearDown();
    }

    public function testDryRunApplyIdempotencyAndRollback(): void
    {
        $before = $this->counts();

        // Dry-run: лише staging.
        $out = $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 't1']);
        self::assertStringContainsString('dry-run', $out);
        self::assertSame($before, $this->counts(), 'dry-run не чіпає задачі');
        self::assertSame(3, (int) $this->db->fetchOne("SELECT COUNT(*) FROM planfix_import_task WHERE batch_id = 't1'"));

        // --apply без прапорця середовища — відмова.
        $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 't1', '--apply' => true], Command::FAILURE);
        self::assertSame($before, $this->counts());

        $_SERVER[PlanfixImportGuard::ENV] = '1';
        $map = $this->dir . '/map.json';
        file_put_contents($map, json_encode(['101' => $this->manager]));
        $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 't1', '--apply' => true, '--user-map' => $map, '--system-ids' => '404']);

        $after = $this->counts();
        self::assertSame($before['task'] + 3, $after['task']);
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
        self::assertStringContainsString('Оплата: Не оплачено', $deal['description']);
        self::assertEqualsCanonicalizing(['Planfix', 'Етап: Ціноутворення', 'Planfix: термінова'], $this->db->fetchFirstColumn(
            'SELECT l.name FROM task_task_label x JOIN task_label l ON l.id = x.label_id WHERE x.task_id = ?',
            [$deal['id']],
        ));

        // Чат: оригінальні дати, хронологія, автори; токен файлу вирізано.
        $messages = $this->db->fetchAllAssociative('SELECT m.kind, m.text, m.created_at, m.author_id, u.first_name, u.last_name FROM task_message m LEFT JOIN telegram_user u ON u.id = m.author_id WHERE m.task_id = ? ORDER BY m.id', [$deal['id']]);
        self::assertSame(['event', 'comment', 'event', 'comment'], array_column($messages, 'kind'));
        self::assertSame(['2024-01-05 09:30:00', '2024-01-05 10:00:00', '2024-01-06 11:00:00', '2024-01-07 12:00:00'], array_column($messages, 'created_at'));
        self::assertSame('Колишній співробітник', $messages[1]['first_name']);
        self::assertSame('#202', $messages[1]['last_name']);
        self::assertStringNotContainsString('planfixauth', $messages[3]['text']);
        self::assertStringContainsString('[файл з Planfix не перенесено]', $messages[3]['text']);

        // Заглушка колишнього: у кошику, відхилена, без Telegram і пароля.
        $ghost = $this->db->fetchAssociative("SELECT * FROM telegram_user WHERE telegram_id = 'planfix-202'");
        self::assertNotFalse($ghost);
        self::assertSame('rejected', $ghost['access_status']);
        self::assertNotNull($ghost['archived_at']);
        self::assertNull($ghost['chat_id']);
        self::assertNull($ghost['password']);

        // Живу людину не засипаємо «+N»: task_read на останнє повідомлення.
        self::assertSame(
            (int) $this->db->fetchOne('SELECT MAX(id) FROM task_message WHERE task_id = ?', [$deal['id']]),
            (int) $this->db->fetchOne('SELECT last_message_id FROM task_read WHERE task_id = ? AND user_id = ?', [$deal['id'], $this->manager]),
        );

        // Службовий обліковий запис (робот/сценарій) — один автор «Planfix (система)».
        self::assertSame(['Planfix', '(система)'], array_values($this->db->fetchAssociative(
            "SELECT u.first_name, u.last_name FROM task_message m JOIN telegram_user u ON u.id = m.author_id WHERE m.text = 'Автоматичне повідомлення сценарію'",
        ) ?: []));

        $closed = $this->task(12);
        self::assertSame('rejected', $closed['status']);
        self::assertSame('2023-03-02 08:00:00', $closed['closed_at']);
        self::assertSame('done', $this->task(13)['status']);

        // Повтор того ж — нічого не дублює.
        $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 't2', '--apply' => true, '--user-map' => $map, '--system-ids' => '404']);
        self::assertSame($after, $this->counts());

        // Нове вивантаження з однією новою дією — додається лише вона.
        $this->export(extraComment: true);
        $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 't3', '--apply' => true, '--user-map' => $map, '--system-ids' => '404']);
        $more = $this->counts();
        self::assertSame($after['task'], $more['task']);
        self::assertSame($after['task_message'] + 1, $more['task_message']);
        self::assertSame('2024-02-01 09:00:00', $this->db->fetchOne('SELECT last_activity_at FROM task WHERE id = ?', [$deal['id']]));

        // Відкат t3 — лише його повідомлення; відкат t1 — усе інше, разом із заглушками й мітками.
        $this->console('plan:planfix:rollback', ['batch' => 't3']);
        self::assertSame($after, $this->counts());
        $dry = $this->console('plan:planfix:rollback', ['batch' => 't1', '--dry-run' => true]);
        self::assertStringContainsString('dry-run', $dry);
        self::assertSame($after, $this->counts());
        $this->console('plan:planfix:rollback', ['batch' => 't1', '--purge-staging' => true]);
        self::assertSame($before, $this->counts());
        self::assertFalse($this->db->fetchOne("SELECT 1 FROM telegram_user WHERE telegram_id LIKE 'planfix-%'"));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM planfix_import_task WHERE batch_id = 't1'"));
    }

    public function testRollbackRefusesWithoutFlag(): void
    {
        $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 't9']);
        $this->console('plan:planfix:rollback', ['batch' => 't9'], Command::FAILURE);
    }

    public function testSinceAndOnlyFilters(): void
    {
        $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 'f1', '--only' => 'deal']);
        self::assertSame(['501'], array_map('strval', $this->db->fetchFirstColumn("SELECT number FROM planfix_import_task WHERE batch_id = 'f1'")));

        $this->console('plan:planfix:import', ['dir' => $this->dir, '--batch' => 'f2', '--since' => '2024-01-01']);
        self::assertSame(['501'], array_map('strval', $this->db->fetchFirstColumn("SELECT number FROM planfix_import_task WHERE batch_id = 'f2'")));
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

    /** Синтетичне вивантаження у форматі Planfix: 1 угода, 2 задачі, 3 людини (101 — діючий, 202 і 303 — колишні). */
    private function export(bool $extraComment = false): void
    {
        $tasks = [
            ['id' => 11, 'number' => 501, 'title' => 'Тестова угода', 'description' => '<p>Опис угоди</p>', 'status' => 'Ціноутворення', 'importance' => 10,
                'owner' => ['id' => 101], 'workers' => ['users' => [['id' => 202]], 'groups' => [['id' => -1, 'name' => 'Неіснуючий відділ']]],
                'createDate' => '05-01-2024 09:30', 'hasEndDate' => true, 'endTime' => '20-01-2024 18:00', 'hasStartDate' => false, 'checkResult' => true,
                'project' => ['id' => 7], 'customData' => ['57554' => 'Не оплачено', '57592' => 'ТОВ Синтетика', '57552' => '0']],
            ['id' => 12, 'number' => 502, 'title' => 'Скасована задача', 'description' => '', 'status' => 'Отмененная', 'importance' => 5,
                'owner' => ['id' => 303], 'workers' => [], 'createDate' => '01-03-2023 08:00', 'hasEndDate' => false, 'hasStartDate' => false, 'checkResult' => true],
            ['id' => 13, 'number' => 503, 'title' => 'Підзадача', 'description' => '', 'status' => 'Завершенная', 'importance' => 5, 'parent' => ['id' => 12],
                'owner' => ['id' => 101], 'workers' => [], 'createDate' => '02-03-2023 08:00', 'hasEndDate' => false, 'hasStartDate' => false, 'checkResult' => true],
        ];
        $actions = [
            ['id' => 1, 'task' => ['id' => 11], 'owner' => ['id' => 101], 'type' => 'TaskCreated', 'dateTime' => '05-01-2024 09:30', 'hidden' => false, 'description' => 'Завдання створене'],
            ['id' => 2, 'task' => ['id' => 11], 'owner' => ['id' => 202], 'type' => 'Comment', 'dateTime' => '05-01-2024 10:00', 'hidden' => false, 'description' => '<p>Перший коментар</p>'],
            ['id' => 3, 'task' => ['id' => 11], 'owner' => ['id' => 101], 'type' => 'TaskOverdued', 'dateTime' => '05-01-2024 11:00', 'hidden' => false, 'description' => '{event21}'],
            ['id' => 4, 'task' => ['id' => 11], 'owner' => ['id' => 101], 'type' => 'WorkerEmployed', 'dateTime' => '06-01-2024 11:00', 'hidden' => false, 'description' => "{event7}: <a href='/user/2' >Тест Колишній</a>"],
            ['id' => 5, 'task' => ['id' => 11], 'owner' => ['id' => 101], 'type' => 'Action', 'dateTime' => '07-01-2024 12:00', 'hidden' => false,
                'description' => '<p>Файл <img src="https://demo.planfix.com/?action=getfile&amp;uniqueid=9&amp;planfixauth=TOKEN"></p>'],
            ['id' => 6, 'task' => ['id' => 12], 'owner' => ['id' => 303], 'type' => 'Comment', 'dateTime' => '02-03-2023 08:00', 'hidden' => false, 'description' => 'Скасовуємо'],
            ['id' => 9, 'task' => ['id' => 12], 'owner' => ['id' => 404], 'type' => 'Action', 'dateTime' => '02-03-2023 07:00', 'hidden' => false, 'description' => 'Автоматичне повідомлення сценарію'],
            ['id' => 7, 'task' => ['id' => 999], 'owner' => ['id' => 101], 'type' => 'TaskCreated', 'dateTime' => '02-03-2023 08:00', 'hidden' => false, 'description' => 'Контакт створений'],
        ];

        if ($extraComment) {
            $actions[] = ['id' => 8, 'task' => ['id' => 11], 'owner' => ['id' => 101], 'type' => 'Comment', 'dateTime' => '01-02-2024 09:00', 'hidden' => false, 'description' => 'Новий коментар'];
        }

        $files = [
            'tasks.json' => $tasks,
            'actions.json' => $actions,
            'users.json' => [['id' => 101, 'name' => 'Тест', 'lastName' => 'Діючий', 'midName' => '', 'postId' => 0]],
            'projects.json' => [['id' => 7, 'title' => 'Тестовий проєкт', 'number' => 1, 'status' => 'Активный']],
        ];

        foreach ($files as $name => $data) {
            file_put_contents($this->dir . '/' . $name, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
    }
}
