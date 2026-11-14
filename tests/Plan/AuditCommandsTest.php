<?php

namespace App\Tests\Plan;

use App\Entity\CrmLogin;
use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Audit\AuditCatalog;
use App\Plan\Event\CommentAdded;
use App\Plan\Event\FileDownloaded;
use App\Plan\Event\PlanEventTypes;
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
 * plan:superadmin (видати/зняти з консолі), plan:audit:prune (строки 36/12 міс,
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

        $ids = [
            'login13' => $recorder->import(new UserLoggedIn($me, $user->getId(), at: $ago('-13 months'))),
            'login11' => $recorder->import(new UserLoggedIn($me, $user->getId(), at: $ago('-11 months'))),
            'download13' => $recorder->import(new FileDownloaded($ref, $user->getId(), fileId: 1, fileType: 'task', at: $ago('-13 months'))),
            'view13' => $recorder->import(new SubjectViewed($ref, $user->getId(), action: 'view', at: $ago('-13 months'))),
            'comment13' => $recorder->import(new CommentAdded($ref, $user->getId(), refId: 1, at: $ago('-13 months'))),
            'comment37' => $recorder->import(new CommentAdded($ref, $user->getId(), refId: 2, at: $ago('-37 months'))),
            'comment35' => $recorder->import(new CommentAdded($ref, $user->getId(), refId: 3, at: $ago('-35 months'))),
        ];
        $oldLogin = new CrmLogin($user, true, null, '10.0.0.1', 'old-agent', '/crm');
        $freshLogin = new CrmLogin($user, true, null, '10.0.0.2', 'fresh-agent', '/crm');
        $this->em->persist($oldLogin);
        $this->em->persist($freshLogin);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE crm_login SET at = ? WHERE id = ?', [$ago('-13 months')->format('Y-m-d H:i:s'), $oldLogin->getId()]);

        $tester = $this->command('plan:audit:prune');
        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('[dry-run]', $tester->getDisplay());
        self::assertSame(array_values($ids), $this->existing($ids), 'dry-run нічого не видаляє');

        self::assertSame(Command::SUCCESS, $tester->execute(['--batch' => '2']));
        self::assertSame([$ids['login11'], $ids['comment13'], $ids['comment35']], $this->existing($ids));
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT 1 FROM crm_login WHERE id = ?', [$oldLogin->getId()]), 'crm_login — лише з --with-crm-login');

        self::assertSame(Command::SUCCESS, $tester->execute(['--with-crm-login' => true]));
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT 1 FROM crm_login WHERE id = ?', [$oldLogin->getId()]));
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT 1 FROM crm_login WHERE id = ?', [$freshLogin->getId()]));

        self::assertSame(Command::INVALID, $tester->execute(['--access-months' => '40']));
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
