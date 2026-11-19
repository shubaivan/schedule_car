<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Telegram\LinkResult;
use App\Plan\Telegram\TelegramLink;
use App\Service\TelegramUserService;
use App\Tasks\Service\StaffDirectory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * «Дублі в кошику»: коли людина підключає Telegram, порожній запис, який бот завів цьому
 * Telegram, звільняється (unlinked-…) і архівується. У кошику «Співробітників» його не видно,
 * а plan:stubs:purge видаляє такі записи, якщо на них ніщо не посилається.
 */
class StubsPurgeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set(Nutgram::class, Nutgram::fake());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testReleasedStubIsNotADuplicateInTheBin(): void
    {
        $person = $this->staff('Тест-Кошик', 'staff-' . bin2hex(random_bytes(6)));
        $stub = self::getContainer()->get(TelegramUserService::class)->initUser(['id' => '700900100', 'first_name' => 'Тест-Кошик', 'chat_id' => '700900100']);

        self::assertSame(LinkResult::Linked, self::getContainer()->get(TelegramLink::class)->attach($person, '700900100', '700900100'));
        self::assertTrue(TelegramLink::isReleasedStub($stub));

        // Справжня картка в кошику — як і раніше видно.
        $fired = $this->staff('Тест-Кошик-Звільнений', 'staff-' . bin2hex(random_bytes(6)));
        $fired->archive();
        $this->em->flush();

        $bin = array_map(static fn (TelegramUser $u) => $u->getId(), self::getContainer()->get(StaffDirectory::class)->list(true));
        self::assertNotContains($stub->getId(), $bin, 'Порожній запис бота — не співробітник, у кошику його нема');
        self::assertContains($fired->getId(), $bin);
        self::assertFalse(TelegramLink::isReleasedStub($fired));
    }

    public function testPurgeDeletesOnlyOldUnreferencedStubs(): void
    {
        $free = $this->releasedStub('-3 days');
        $fresh = $this->releasedStub('-1 hour');
        $referenced = $this->releasedStub('-3 days');
        $withToken = $this->releasedStub('-3 days');
        $staff = $this->staff('Тест-Заглушка-Справжній', 'unlinked-0-staff');
        $staff->decideAccess(AccessStatus::Approved, null);
        $staff->archive();
        $this->em->flush();
        $this->db->executeStatement("UPDATE telegram_user SET archived_at = NOW() - INTERVAL '3 days' WHERE id = ?", [$staff->getId()]);

        // Посилання з історією (журнал) — тримає; службовий токен входу — ні (піде каскадом).
        $this->db->executeStatement("INSERT INTO plan_event (id, type, subject_type, subject_id, occurred_at, payload, channel, actor_id) VALUES ((SELECT COALESCE(MAX(id), 0) + 1 FROM plan_event), 'user_logged_in', 'user', ?, NOW(), '{}', 'web', ?)", [$referenced, $referenced]);
        $this->db->executeStatement('INSERT INTO telegram_link_token (token_hash, user_id, expires_at, created_at) VALUES (?, ?, NOW(), NOW())', [hash('sha256', 'purge-test'), $withToken]);

        $dry = $this->purge(['--dry-run' => true]);
        self::assertStringContainsString('[dry-run] Буде видалено 2 з 3', $dry);
        self::assertStringContainsString('plan_event.actor_id ×1', $dry);
        self::assertSame(4, $this->alive([$free, $fresh, $referenced, $withToken]), 'dry-run нічого не видаляє');

        $out = $this->purge([]);
        self::assertStringContainsString('Видалено 2 з 3', $out);
        self::assertSame(0, $this->alive([$free, $withToken]), 'Без посилань — видалено (токен — разом із записом)');
        self::assertSame(1, $this->alive([$fresh]), 'Щойно звільнений — чекає --min-hours');
        self::assertSame(1, $this->alive([$referenced]), 'На запис посилається журнал — лишається в архіві');
        self::assertSame(1, $this->alive([(int) $staff->getId()]), 'Підтверджена картка — не порожній запис, не чіпаємо');
    }

    /** @param array<string, mixed> $options */
    private function purge(array $options): string
    {
        $tester = new CommandTester(new Application(self::$kernel)->find('plan:stubs:purge'));
        self::assertSame(Command::SUCCESS, $tester->execute($options));

        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }

    /** @param list<int> $ids */
    private function alive(array $ids): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM telegram_user WHERE id IN (' . implode(',', $ids) . ')');
    }

    private function releasedStub(string $archivedAgo): int
    {
        $stub = new TelegramUser()->setTelegramId('tmp-' . bin2hex(random_bytes(6)))->setFirstName('Тест-Заглушка');
        $stub->archive();
        $this->em->persist($stub);
        $this->em->flush();
        $id = (int) $stub->getId();
        $this->db->executeStatement('UPDATE telegram_user SET telegram_id = ?, archived_at = ?::timestamp WHERE id = ?', [TelegramLink::RELEASED_PREFIX . $id . '-test', new DateTimeImmutable($archivedAgo, new DateTimeZone('Europe/Kyiv'))->format('Y-m-d H:i:s'), $id]);

        return $id;
    }

    private function staff(string $name, string $telegramId): TelegramUser
    {
        $user = new TelegramUser()->setTelegramId($telegramId)->setFirstName($name)->setEmail(uniqid('stub', true) . '@zavod.test');
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
