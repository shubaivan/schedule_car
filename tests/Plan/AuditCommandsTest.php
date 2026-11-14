<?php

namespace App\Tests\Plan;

use App\Entity\CrmLogin;
use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Audit\AuditCatalog;
use App\Plan\Event\CommentAdded;
use App\Plan\Event\FileDownloaded;
use App\Plan\Event\PlanEventTypes;
use App\Plan\Event\StaffChanged;
use App\Plan\Event\StatusChanged;
use App\Plan\Event\StockMoved;
use App\Plan\Event\SubjectCreated;
use App\Plan\Event\SubjectViewed;
use App\Plan\Event\UserLoggedIn;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Tests\Warehouse\WarehouseFixtures;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Service\ActivityLog;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * plan:superadmin (видати/зняти з консолі), plan:audit:prune (строк 180 днів,
 * --dry-run нічого не видаляє), зведення повторних переглядів картки складу
 * й повна довідка типів подій для фільтра.
 */
class AuditCommandsTest extends KernelTestCase
{
    use WarehouseFixtures;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
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

    public function testCatalogCoversEveryEventType(): void
    {
        foreach (PlanEventTypes::types() as $type) {
            self::assertArrayHasKey($type, AuditCatalog::TYPES, $type);
            self::assertNotNull(AuditCatalog::groupOf($type), $type . ' без групи');
        }

        foreach (SubjectType::cases() as $kind) {
            self::assertArrayHasKey($kind->value, AuditCatalog::SUBJECTS);
        }
    }

    public function testSuperadminGrantRevokeByEmailAndPhone(): void
    {
        $user = $this->human('ivan', email: 'audit-cmd-' . uniqid() . '@example.test', phone: '+380671234' . random_int(100, 999));
        $tester = $this->command('plan:superadmin');

        self::assertSame(Command::INVALID, $tester->execute(['login' => $user->getEmail()]));
        self::assertSame(Command::INVALID, $tester->execute(['login' => $user->getEmail(), '--grant' => true, '--revoke' => true]));
        self::assertSame(Command::FAILURE, $tester->execute(['login' => 'nobody-' . uniqid() . '@example.test', '--grant' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['login' => (string) $user->getPhoneNumber(), '--grant' => true]), 'видача — лише за email');

        self::assertSame(Command::SUCCESS, $tester->execute(['login' => strtoupper((string) $user->getEmail()), '--grant' => true, '--dry-run' => true]));
        $this->em->refresh($user);
        self::assertFalse($user->isSuperadmin(), 'dry-run нічого не пише');

        $mark = $this->mark();
        self::assertSame(Command::SUCCESS, $tester->execute(['login' => $user->getEmail(), '--grant' => true]));
        $this->em->refresh($user);
        self::assertTrue($user->isSuperadmin());
        self::assertContains('ROLE_PLAN_SUPERADMIN', $user->getRoles());

        $events = $this->em->getConnection()->fetchAllAssociative('SELECT type, subject_type, subject_id, channel, payload FROM plan_event WHERE id > ?', [$mark]);
        self::assertCount(1, $events);
        self::assertSame(['subject_updated', 'user', (int) $user->getId(), 'cli'], [$events[0]['type'], $events[0]['subject_type'], (int) $events[0]['subject_id'], $events[0]['channel']]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--list' => true]));
        self::assertStringContainsString((string) $user->getEmail(), $tester->getDisplay());

        // Повторна видача — нічого не змінює і нової події не пише.
        $mark = $this->mark();
        self::assertSame(Command::SUCCESS, $tester->execute(['login' => $user->getEmail(), '--grant' => true]));
        self::assertSame($mark, $this->mark());

        self::assertSame(Command::SUCCESS, $tester->execute(['login' => '067 123 4' . substr((string) $user->getPhoneNumber(), -3), '--revoke' => true]));
        $this->em->refresh($user);
        self::assertFalse($user->isSuperadmin());
        self::assertNotContains('ROLE_PLAN_SUPERADMIN', $user->getRoles());
    }

    public function testArchivedPersonCannotBecomeSuperadmin(): void
    {
        $user = $this->human('gone', email: 'audit-cmd-' . uniqid() . '@example.test');
        $user->archive();
        $this->em->flush();

        self::assertSame(Command::FAILURE, $this->command('plan:superadmin')->execute(['login' => $user->getEmail(), '--grant' => true]));
        $this->em->refresh($user);
        self::assertFalse($user->isSuperadmin());
    }

    public function testPruneKeepsRetentionWindowsAndDryRunDeletesNothing(): void
    {
        $user = $this->human('old', email: 'audit-cmd-' . uniqid() . '@example.test');
        $kyiv = new DateTimeZone('Europe/Kyiv');
        $ago = static fn (string $modify) => (new DateTimeImmutable('now', $kyiv))->modify($modify);
        $recorder = self::getContainer()->get(PlanEventRecorder::class);
        $ref = SubjectRef::task(900000010);
        $me = SubjectRef::user((int) $user->getId());

        // Строк 180 днів (PLAN_AUDIT_RETENTION_DAYS, .env = 180) — лише для службових типів
        // (PlanEventTypes::AUDIT_ONLY); доменна історія живе, скільки б їй не було.
        $item = SubjectRef::of(SubjectType::WhItem, 900000011);
        $ids = [
            'login181' => $recorder->import(new UserLoggedIn($me, $user->getId(), at: $ago('-181 days'))),
            'login179' => $recorder->import(new UserLoggedIn($me, $user->getId(), at: $ago('-179 days'))),
            'download181' => $recorder->import(new FileDownloaded($ref, $user->getId(), fileId: 1, fileType: 'task', at: $ago('-181 days'))),
            'view181' => $recorder->import(new SubjectViewed($ref, $user->getId(), action: 'view', at: $ago('-181 days'))),
            'comment181' => $recorder->import(new CommentAdded($ref, $user->getId(), refId: 1, at: $ago('-181 days'))),
            'comment179' => $recorder->import(new CommentAdded($ref, $user->getId(), refId: 2, at: $ago('-179 days'))),
            'comment40' => $recorder->import(new CommentAdded($ref, $user->getId(), refId: 3, at: $ago('-40 days'))),
            'created400' => $recorder->import(new SubjectCreated($ref, $user->getId(), title: 'Стара задача', at: $ago('-400 days'))),
            'status400' => $recorder->import(new StatusChanged($ref, $user->getId(), from: 'new', to: 'done', at: $ago('-400 days'))),
            'stock400' => $recorder->import(new StockMoved($item, $user->getId(), movementType: 'in', itemIds: [900000011], at: $ago('-400 days'))),
            'staff400' => $recorder->import(new StaffChanged($me, $user->getId(), action: 'update', fields: ['role'], at: $ago('-400 days'))),
        ];
        $conn = $this->em->getConnection();
        // Хроніка тримається на останній доменній події суб'єкта — вона має пережити ретеншн.
        $conn->executeStatement('INSERT INTO plan_subject_activity (subject_type, subject_id, last_event_id, last_at) VALUES (?, ?, ?, ?)', ['task', 900000010, $ids['comment181'], $ago('-181 days')->format('Y-m-d H:i:s')]);
        // plan_event_consumed: старші 7 днів — геть, свіжі — лишаються.
        $conn->executeStatement('INSERT INTO plan_event_consumed (event_id, consumer, consumed_at) VALUES (?, ?, ?), (?, ?, ?)', [
            $ids['comment181'], 'test-old', $ago('-8 days')->format('Y-m-d H:i:s'),
            $ids['comment40'], 'test-fresh', $ago('-1 day')->format('Y-m-d H:i:s'),
        ]);
        $oldLogin = new CrmLogin($user, true, null, '10.0.0.1', 'old-agent', '/crm');
        $freshLogin = new CrmLogin($user, true, null, '10.0.0.2', 'fresh-agent', '/crm');
        $this->em->persist($oldLogin);
        $this->em->persist($freshLogin);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE crm_login SET at = ? WHERE id = ?', [$ago('-181 days')->format('Y-m-d H:i:s'), $oldLogin->getId()]);

        $tester = $this->command('plan:audit:prune');
        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('[dry-run]', $tester->getDisplay());
        self::assertStringContainsString('180 дн.', $tester->getDisplay());
        self::assertSame(array_values($ids), $this->existing($ids), 'dry-run нічого не видаляє');

        // --days перевизначає строк лише для цього запуску.
        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true, '--days' => '30']));
        self::assertStringContainsString('30 дн.', $tester->getDisplay());
        self::assertSame(array_values($ids), $this->existing($ids));

        self::assertSame(Command::SUCCESS, $tester->execute(['--batch' => '2']));
        // Пішли лише вхід, скачування, перегляд і зміна картки, старші 180 днів; уся доменна історія ціла.
        self::assertSame([
            $ids['login179'], $ids['comment181'], $ids['comment179'], $ids['comment40'],
            $ids['created400'], $ids['status400'], $ids['stock400'],
        ], $this->existing($ids));
        self::assertSame($ids['comment181'], (int) $conn->fetchOne("SELECT a.last_event_id FROM plan_subject_activity a JOIN plan_event e ON e.id = a.last_event_id WHERE a.subject_type = 'task' AND a.subject_id = 900000010"), 'рядок Хроніки на місці');
        self::assertSame(['test-fresh'], $conn->fetchFirstColumn("SELECT consumer FROM plan_event_consumed WHERE consumer LIKE 'test-%'"));
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT 1 FROM crm_login WHERE id = ?', [$oldLogin->getId()]), 'crm_login — лише з --with-crm-login');

        self::assertSame(Command::SUCCESS, $tester->execute(['--with-crm-login' => true]));
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT 1 FROM crm_login WHERE id = ?', [$oldLogin->getId()]));
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT 1 FROM crm_login WHERE id = ?', [$freshLogin->getId()]));

        self::assertSame(Command::INVALID, $tester->execute(['--days' => '0']));
        self::assertSame(Command::INVALID, $tester->execute(['--batch' => '0']));
    }

    public function testBackfillDoesNotDuplicateLiveFailedLogin(): void
    {
        $user = $this->human('victim', email: 'audit-cmd-' . uniqid() . '@example.test');
        $failed = new CrmLogin(null, false, 'Invalid credentials.', '10.0.0.9', 'agent', '/crm');
        $this->em->persist($failed);
        $this->em->flush();
        $conn = $this->em->getConnection();
        $conn->executeStatement("UPDATE crm_login SET at = '2020-01-01 10:00:00' WHERE id = ?", [$failed->getId()]);

        // Живий журнал: невдалий вхід на впізнану людину (user-N); crm_login її не знає (user_id NULL).
        self::getContainer()->get(PlanEventRecorder::class)->import(new \App\Plan\Event\LoginFailed(
            SubjectRef::user((int) $user->getId()),
            null,
            reason: 'Invalid credentials.',
            refId: (int) $failed->getId(),
            at: new DateTimeImmutable('2020-01-01 10:00:01', new DateTimeZone('Europe/Kyiv')),
        ));

        self::assertSame(Command::SUCCESS, $this->command('plan:events:backfill')->execute([]));
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM plan_event WHERE type = 'login_failed' AND (payload->>'refId')::int = ?", [$failed->getId()]));
    }

    public function testRepeatedCardViewsCollapseButScansDoNot(): void
    {
        $manager = $this->person();
        $item = $this->item();
        $log = self::getContainer()->get(ActivityLog::class);
        $mark = $this->mark();

        $log->record(ActivityAction::View, $item, $manager, WhActivity::CHANNEL_ADMIN);
        $log->record(ActivityAction::View, $item, $manager, WhActivity::CHANNEL_ADMIN);
        $log->record(ActivityAction::View, $item, $manager, WhActivity::CHANNEL_ADMIN);
        $log->record(ActivityAction::Scan, $item, $manager, WhActivity::CHANNEL_BOT);
        $log->record(ActivityAction::Scan, $item, $manager, WhActivity::CHANNEL_BOT);
        $log->record(ActivityAction::View, $item, $this->person(), WhActivity::CHANNEL_ADMIN);

        $actions = $this->em->getConnection()->fetchFirstColumn(
            "SELECT payload->>'action' FROM plan_event WHERE id > ? AND type = 'subject_viewed' ORDER BY id",
            [$mark],
        );
        self::assertSame(['view', 'scan', 'scan', 'view'], $actions);

        // Журнал складу (Twig) — як і раніше, кожен перегляд.
        self::assertSame(4, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM wh_activity WHERE subject_type = 'item' AND subject_id = ? AND action = 'view'",
            [$item->getId()],
        ));
    }

    private function mark(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM plan_event');
    }

    /**
     * @param array<string, int> $ids
     *
     * @return list<int>
     */
    private function existing(array $ids): array
    {
        return array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM plan_event WHERE id IN (' . implode(',', $ids) . ') ORDER BY id',
        ));
    }

    private function command(string $name): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find($name));
    }

    private function human(string $name, string $email, ?string $phone = null): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('audit-cmd-' . $name . '-' . uniqid())
            ->setFirstName(ucfirst($name))
            ->setEmail($email)
            ->setPhoneNumber($phone);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
