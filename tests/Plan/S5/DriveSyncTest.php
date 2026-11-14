<?php

namespace App\Tests\Plan\S5;

use App\Command\DriveSyncCommand;
use App\Plan\Drive\Command\PlanDriveSyncCommand;
use App\Plan\Drive\DriveClientFactory;
use App\Plan\Drive\DriveException;
use App\Plan\Drive\DrivePaths;
use App\Plan\Drive\DriveSync;
use App\Plan\Drive\DriveSyncResult;
use App\Plan\Drive\FakeDriveClient;
use App\Plan\Share\FileType;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Enum\Unit;
use App\Supply\Repository\SupplyAttachmentRepository;
use App\Supply\Service\AttachFile;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\GoogleDriveMirror;
use App\Tasks\Enum\WorkRole;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * S5: черга копіювання на Диск з фейковим DriveClient — дерево тек, ідемпотентність,
 * backoff і failed, invalid_grant (одна подія суперадмінам), квота (пауза без штрафу),
 * старий supply:drive-sync не зачеплено.
 */
class DriveSyncTest extends KernelTestCase
{
    use S5Fixtures;

    private Connection $db;
    private DriveSync $sync;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->sync = self::getContainer()->get(DriveSync::class);
        $this->db->beginTransaction();
        $this->isolateDriveQueue();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }

        parent::tearDown();
    }

    public function testTaskFileLandsInPlanTreeOnceEvenIfDbWriteWasLost(): void
    {
        $author = $this->person(WorkRole::Manager, 'mgr');
        $task = $this->task($author, 'Плити / перекриття ПК 60-12');
        $file = $this->taskFile($task, $author, 'кошторис.txt');
        $drive = new FakeDriveClient();

        $result = $this->sync->run($drive, 50);

        self::assertSame(1, $result->sent);
        $row = $this->row('task_attachment', (int) $file->getId());
        self::assertSame('done', $row['drive_state']);
        self::assertNotNull($row['drive_file_id']);
        $year = $task->getCreatedAt()->format('Y');
        self::assertSame([sprintf('План/%s/Задачі/№%s Плити - перекриття ПК 60-12/кошторис.txt', $year, $task->number())], $drive->paths());

        // Повторний прогін нічого не шле.
        self::assertSame(0, $this->sync->run($drive, 50)->sent);

        // Файл ліг на Диск, а запис у базу «загубився» — другої копії немає.
        $this->db->executeStatement("UPDATE task_attachment SET drive_file_id = NULL, drive_state = 'pending' WHERE id = ?", [$file->getId()]);
        $again = $this->sync->run($drive, 50);
        self::assertSame(1, $again->alreadyThere);
        self::assertSame(1, $drive->uploads);
        self::assertSame('done', $this->row('task_attachment', (int) $file->getId())['drive_state']);
    }

    public function testSameNameInFolderGetsIdSuffix(): void
    {
        $author = $this->person(WorkRole::Manager, 'mgr');
        $task = $this->task($author, 'Два акти');
        $a = $this->taskFile($task, $author, 'акт.txt', 'перший');
        $b = $this->taskFile($task, $author, 'акт.txt', 'другий');
        $drive = new FakeDriveClient();

        $this->sync->run($drive, 50);

        $names = array_map(static fn (string $p) => basename($p), $drive->paths());
        self::assertSame(['акт.txt', sprintf('акт (%d).txt', $b->getId())], $names);
        self::assertNotSame($a->getId(), $b->getId());
    }

    public function testSixFailuresWithBackoffThenFailedAndRetry(): void
    {
        $author = $this->person(WorkRole::Manager, 'mgr');
        $file = $this->taskFile($this->task($author, 'Збої'), $author);
        $drive = (new FakeDriveClient())->failNext(DriveException::transient('Google 500'), 6);
        $now = new DateTimeImmutable('2030-01-01 10:00:00', new DateTimeZone('Europe/Kyiv'));

        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $result = $this->sync->run($drive, 50, $now);
            self::assertSame(1, $result->failed, 'спроба ' . $attempt);
            $row = $this->row('task_attachment', (int) $file->getId());
            self::assertSame($attempt, (int) $row['drive_attempts']);

            if ($attempt < 6) {
                self::assertSame('pending', $row['drive_state']);
                $next = $now->modify(sprintf('+%d minutes', 2 ** $attempt));
                self::assertSame($next->format('Y-m-d H:i:s'), $row['drive_next_at']);
                // До настання backoff файл не чіпаємо.
                self::assertSame(0, $this->sync->run($drive, 50, $now->modify('+1 minute'))->failed);
                $now = $next;
            }
        }

        $row = $this->row('task_attachment', (int) $file->getId());
        self::assertSame('failed', $row['drive_state']);
        self::assertSame('Google 500', $row['drive_error']);
        self::assertSame(0, $this->sync->run($drive, 50, $now->modify('+1 day'))->sent, 'failed більше не пробуємо самі');

        self::assertTrue($this->sync->retry(FileType::Task, (int) $file->getId()));
        $row = $this->row('task_attachment', (int) $file->getId());
        self::assertSame(['pending', 0], [$row['drive_state'], (int) $row['drive_attempts']]);
        self::assertSame(1, $this->sync->run($drive, 50, $now)->sent);
    }

    public function testInvalidGrantStopsSyncWithOneEventForSuperadmins(): void
    {
        $super = $this->person(WorkRole::Admin, 'super', superadmin: true);
        $author = $this->person(WorkRole::Manager, 'mgr');
        $file = $this->taskFile($this->task($author, 'Токен'), $author);
        $this->taskFile($this->task($author, 'Токен 2'), $author);
        $before = $this->maxEventId();
        $drive = (new FakeDriveClient())->failNext(DriveException::auth('invalid_grant'));

        $first = $this->sync->run($drive, 50);
        $second = $this->sync->run($drive, 50);

        self::assertSame(DriveSyncResult::STOP_AUTH, $first->stopped);
        self::assertSame(DriveSyncResult::STOP_AUTH, $second->stopped);
        self::assertSame(0, (int) $this->row('task_attachment', (int) $file->getId())['drive_attempts'], 'файлу спроба не рахується');

        $events = $this->db->fetchFirstColumn("SELECT id FROM plan_event WHERE type = 'drive_auth_broken' AND id > ?", [$before]);
        self::assertCount(1, $events);
        self::assertContains(
            $super->getId(),
            array_map('intval', $this->db->fetchFirstColumn('SELECT user_id FROM notification WHERE event_id = ?', [$events[0]])),
        );
        self::assertNotNull($this->sync->status()['authBrokenAt']);

        $this->sync->resume();
        self::assertSame(2, $this->sync->run($drive, 50)->sent);
    }

    public function testQuotaPausesAnHourWithoutPenalty(): void
    {
        $author = $this->person(WorkRole::Manager, 'mgr');
        $file = $this->taskFile($this->task($author, 'Квота'), $author);
        $now = new DateTimeImmutable('2030-01-01 10:00:00', new DateTimeZone('Europe/Kyiv'));
        $drive = (new FakeDriveClient())->failNext(DriveException::quota('rateLimitExceeded'));

        self::assertSame(DriveSyncResult::STOP_QUOTA, $this->sync->run($drive, 50, $now)->stopped);
        self::assertSame(0, (int) $this->row('task_attachment', (int) $file->getId())['drive_attempts']);
        self::assertSame(DriveSyncResult::STOP_QUOTA, $this->sync->run($drive, 50, $now->modify('+30 minutes'))->stopped);
        self::assertSame(1, $this->sync->run($drive, 50, $now->modify('+61 minutes'))->sent);
    }

    public function testParallelRunIsSkipped(): void
    {
        $author = $this->person(WorkRole::Manager, 'mgr');
        $this->taskFile($this->task($author, 'Паралельно'), $author);
        $other = \Doctrine\DBAL\DriverManager::getConnection($this->db->getParams());
        $other->fetchOne('SELECT pg_advisory_lock(7201151400)');

        try {
            $result = $this->sync->run(new FakeDriveClient(), 50);
        } finally {
            $other->fetchOne('SELECT pg_advisory_unlock(7201151400)');
            $other->close();
        }

        self::assertSame(DriveSyncResult::STOP_LOCKED, $result->stopped);
        self::assertSame(0, $result->sent);
    }

    public function testDeletedMessageFileIsSkipped(): void
    {
        $author = $this->person(WorkRole::Manager, 'mgr');
        $file = $this->taskFile($this->task($author, 'Видалене'), $author);
        $this->tasks()->deleteMessage($file->getMessage(), $author);

        $result = $this->sync->run(new FakeDriveClient(), 50);

        self::assertSame(1, $result->skipped);
        self::assertSame('skipped', $this->row('task_attachment', (int) $file->getId())['drive_state']);
    }

    public function testSupplyDocumentGoesUnderZayavky(): void
    {
        $author = $this->person(WorkRole::Manager, 'author');
        $request = self::getContainer()->get(CreateRequest::class)($author, new CreateRequestInput(item: 'Цемент М400', quantity: '10', unit: Unit::Pack));
        self::getContainer()->get(AttachFile::class)($request, $author, 'накладна', 'накладна.pdf', 'application/pdf');
        $attachment = self::getContainer()->get(SupplyAttachmentRepository::class)->findOneBy(['request' => $request]);
        self::assertNotNull($attachment);
        $drive = new FakeDriveClient();

        $this->sync->run($drive, 50, only: FileType::Supply);

        $year = $request->getCreatedAt()->format('Y');
        self::assertSame([sprintf('План/%s/Заявки/№%s Цемент М400/%s', $year, str_replace('/', '-', $request->getNumber()), $attachment->getDriveName())], $drive->paths());
    }

    public function testDemoSeedFilesNeverReachDrive(): void
    {
        $author = $this->person(WorkRole::Manager, 'demo');
        $marks = $this->sync->marks();
        $demoFile = $this->taskFile($this->task($author, 'Демо-угода'), $author, 'демо.txt');
        $request = self::getContainer()->get(CreateRequest::class)($author, new CreateRequestInput(item: 'Демо-цемент', quantity: '1', unit: Unit::Pack));
        self::getContainer()->get(AttachFile::class)($request, $author, 'демо', 'демо.pdf', 'application/pdf');
        $demoSupply = self::getContainer()->get(SupplyAttachmentRepository::class)->findOneBy(['request' => $request]);
        self::assertNotNull($demoSupply);

        self::assertSame(2, $this->sync->keepOffDrive($marks));
        $realFile = $this->taskFile($this->task($author, 'Справжня угода'), $author, 'справжній.txt');

        $drive = new FakeDriveClient();
        $result = $this->sync->run($drive, 50);

        self::assertSame(1, $result->sent, 'на Диск — лише справжній файл');
        self::assertCount(1, $drive->paths());
        self::assertStringEndsWith('/справжній.txt', $drive->paths()[0]);
        foreach ([['task_attachment', (int) $demoFile->getId()], ['supply_attachment', (int) $demoSupply->getId()]] as [$table, $id]) {
            $row = $this->row($table, $id);
            self::assertSame('skipped', $row['drive_state'], $table);
            self::assertNull($row['drive_file_id'], $table);
            self::assertSame(DriveSync::DEMO_NOTE, $this->db->fetchOne(sprintf('SELECT drive_error FROM %s WHERE id = ?', $table), [$id]));
        }
        self::assertSame('done', $this->row('task_attachment', (int) $realFile->getId())['drive_state']);

        // Ні «Повторити», ні старе дзеркало стенда заявок демо-файл назад у чергу не повертають.
        self::assertFalse($this->sync->retry(FileType::Task, (int) $demoFile->getId()));
        self::assertSame(0, $this->sync->retryAllFailed());
        self::assertNotContains($demoSupply, self::getContainer()->get(SupplyAttachmentRepository::class)->findNotMirrored(500));
    }

    public function testPathNaming(): void
    {
        $paths = new DrivePaths('План');
        $at = new DateTimeImmutable('2026-11-15 12:00:00');

        self::assertSame(['План', '2026', 'Задачі', '№0012 Плити'], $paths->task($at, '0012', '  Плити  '));
        self::assertSame(['План', '2026', 'Заявки', '№042-2026 Цемент'], $paths->supply($at, '042/2026', 'Цемент'));
        self::assertSame(['План', '2026', 'Склад', 'Клієнти', 'ТОВ А-Б'], $paths->warehouse($at, ['Клієнти', 'ТОВ А/Б']));
        self::assertSame(['План', '2026', 'Ліди', '2026-11-15 Олег'], $paths->lead($at, 'Олег'));
        self::assertSame('акт (7).pdf', DrivePaths::withId('акт.pdf', 7));
        self::assertSame('README (7)', DrivePaths::withId('README', 7));
        self::assertSame(120, mb_strlen(DrivePaths::safe(str_repeat('я', 300))));
    }

    /** Стенд заявок: plan:drive-sync мовчить, старий supply:drive-sync іде своїм шляхом; на Плані — навпаки. */
    public function testLayoutsDoNotCross(): void
    {
        $standFactory = new DriveClientFactory('', '', '', '', null);
        $command = new PlanDriveSyncCommand($this->sync, $standFactory, $this->db, []);
        $tester = new CommandTester($command);
        $tester->execute([]);
        self::assertStringContainsString('supply:drive-sync', $tester->getDisplay());

        $legacy = new CommandTester((new Application(self::$kernel))->find('supply:drive-sync'));
        $legacy->execute([]);
        self::assertStringNotContainsString('plan:drive-sync', $legacy->getDisplay(), 'дефолт — стенд, стара команда працює як була');

        $planLegacy = new DriveSyncCommand(
            self::getContainer()->get(SupplyAttachmentRepository::class),
            self::getContainer()->get(GoogleDriveMirror::class),
            self::getContainer()->get(AttachFile::class),
            self::getContainer()->get(\App\Warehouse\Repository\WhDocumentRepository::class),
            self::getContainer()->get(\App\Warehouse\Service\WarehouseDriveMirror::class),
            self::getContainer()->get(\App\Warehouse\Service\DocumentStore::class),
            'plan',
        );
        $tester = new CommandTester($planLegacy);
        $tester->execute([]);
        self::assertStringContainsString('plan:drive-sync', $tester->getDisplay());
    }

    public function testDryRunListsWithoutUploading(): void
    {
        $author = $this->person(WorkRole::Manager, 'mgr');
        $file = $this->taskFile($this->task($author, 'Сухий прогін'), $author);
        $factory = new DriveClientFactory('', '', '', '', 'plan');
        $tester = new CommandTester(new PlanDriveSyncCommand($this->sync, $factory, $this->db, [self::getContainer()->get(\App\Plan\Drive\Source\TaskFiles::class)]));

        $tester->execute(['--dry-run' => true]);

        self::assertStringContainsString('Задачі/№', $tester->getDisplay());
        self::assertSame('pending', $this->row('task_attachment', (int) $file->getId())['drive_state']);
    }

    /** @return array<string, mixed> */
    private function row(string $table, int $id): array
    {
        return (array) $this->db->fetchAssociative("SELECT drive_state, drive_attempts, drive_error, drive_next_at, drive_file_id FROM $table WHERE id = ?", [$id]);
    }

    private function maxEventId(): int
    {
        return (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM plan_event');
    }
}
