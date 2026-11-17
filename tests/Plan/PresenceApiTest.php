<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Presence\PresenceTracker;
use App\Tasks\Enum\WorkRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * «Хто зараз у Плані» (/api/plan/presence): heartbeat пише розділ, GET нічого не пише,
 * старше 3 хвилин — не активний і прибирається, в списку лише люди Плану, «Вийти» — одразу зникає.
 * Усе в транзакції тесту: now() у PostgreSQL — час її початку, тож «давність» задаємо UPDATE-ом.
 */
class PresenceApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->db->beginTransaction();
        // Тестова база спільна: чужі рядки (інший тест, ручний запуск) не повинні впливати на лічильник.
        $this->db->executeStatement('DELETE FROM plan_presence');
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        parent::tearDown();
    }

    public function testHeartbeatRecordsSectionAndListsMeFirstWithPhones(): void
    {
        $me = $this->user('Яна', WorkRole::Manager);
        $colleague = $this->user('Богдан', WorkRole::Production, '0933504442');

        $this->client->loginUser($colleague);
        $this->beat('warehouse');

        $this->client->loginUser($me);
        $out = $this->beat('tasks');

        self::assertSame(2, $out['count']);
        self::assertSame(60, $out['heartbeatSeconds']);
        self::assertSame(180, $out['activeSeconds']);
        [$first, $second] = $out['people'];

        self::assertSame($me->getId(), $first['id'], 'я — першим');
        self::assertTrue($first['me']);
        self::assertSame('Задачі', $first['sectionLabel']);

        self::assertSame($colleague->getId(), $second['id']);
        self::assertFalse($second['me']);
        self::assertSame('warehouse', $second['section']);
        self::assertSame('Склад', $second['sectionLabel']);
        self::assertSame('093 350 44 42', $second['phone'], 'телефон — як у довіднику людей');
        self::assertSame('Богдан Тест', $second['name']);
        self::assertNotEmpty($second['position']);
        self::assertArrayNotHasKey('subject', $second, 'що саме відкрито — не віддаємо');
    }

    public function testGetNeverWritesPresence(): void
    {
        $me = $this->user('Читач', WorkRole::Manager);
        $this->client->loginUser($me);

        $out = $this->get();

        self::assertSame(0, $out['count'], 'GET не робить людину активною');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_presence'));
    }

    public function testSameSectionWithinTwentySecondsIsNotRewrittenButNewSectionIs(): void
    {
        $me = $this->user('Швидкий', WorkRole::Manager);
        $this->client->loginUser($me);
        $this->beat('tasks');

        $this->age($me, 10);
        $before = $this->seenAt($me);
        $this->beat('tasks');
        self::assertSame($before, $this->seenAt($me), 'той самий розділ за 10 с — рядок не переписуємо');

        $this->beat('supply');
        self::assertSame('supply', $this->db->fetchOne('SELECT section FROM plan_presence WHERE user_id = ?', [$me->getId()]), 'новий розділ — пишемо одразу');

        $this->age($me, 30);
        $this->beat('supply');
        self::assertNotSame($before, $this->seenAt($me));
        self::assertGreaterThan(strtotime($before), strtotime($this->seenAt($me)), 'старіший за 20 с — оновлюємо');
    }

    public function testStaleAfterThreeMinutesIsHiddenAndPrunedOnHeartbeat(): void
    {
        $me = $this->user('Свіжий', WorkRole::Manager);
        $away = $this->user('Пішов', WorkRole::Manager);
        $almost = $this->user('Ось-ось', WorkRole::Manager);

        foreach ([$away, $almost] as $user) {
            $this->client->loginUser($user);
            $this->beat('overview');
        }
        $this->age($away, PresenceTracker::ACTIVE_SECONDS + 5);
        $this->age($almost, PresenceTracker::ACTIVE_SECONDS - 30);

        $this->client->loginUser($me);
        $ids = array_column($this->get()['people'], 'id');
        self::assertNotContains($away->getId(), $ids, 'понад 3 хвилини без heartbeat — уже не «зараз»');
        self::assertContains($almost->getId(), $ids);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_presence WHERE user_id = ?', [$away->getId()]), 'GET нічого не видаляє');

        $this->beat('overview');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_presence WHERE user_id = ?', [$away->getId()]), 'heartbeat прибирає застарілі');
    }

    public function testOnlyPlanPeopleAreListedAndUnapprovedCannotLook(): void
    {
        $me = $this->user('Директор', WorkRole::Director);
        $archived = $this->user('Архівний', WorkRole::Manager);
        $pending = $this->user('Новенький', WorkRole::Manager, approved: false);

        $this->client->loginUser($archived);
        $this->beat('tasks');
        $archived->archive();
        $this->em->flush();

        // Не затверджений: не бачить списку і сам не рахується.
        $this->client->loginUser($pending);
        $this->client->request('POST', '/api/plan/presence', server: ['CONTENT_TYPE' => 'application/json'], content: '{"section":"tasks"}');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/plan/presence');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($me);
        $ids = array_column($this->beat('overview')['people'], 'id');
        self::assertSame([$me->getId()], $ids, 'в архіві чи без доступу — не в списку');
    }

    public function testOnlySuperadminCanBeInAdminSection(): void
    {
        $me = $this->user('Не адмін', WorkRole::Manager);
        $this->client->loginUser($me);

        self::assertSame('other', $this->beat('admin')['people'][0]['section'], 'журнал аудиту — лише суперадміну');
    }

    public function testUnknownSectionIsJustPlan(): void
    {
        $me = $this->user('Хтось', WorkRole::Manager);
        $this->client->loginUser($me);

        $person = $this->beat('task-12-secret')['people'][0];

        self::assertSame('other', $person['section']);
        self::assertSame('План', $person['sectionLabel']);
    }

    public function testLogoutRemovesMeAtOnce(): void
    {
        $me = $this->user('Виходжу', WorkRole::Manager);
        $this->client->loginUser($me);
        $this->beat('tasks');

        $this->client->request('GET', '/crm/logout');

        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_presence WHERE user_id = ?', [$me->getId()]));
    }

    /** @return array<string, mixed> */
    private function beat(string $section): array
    {
        $this->client->request('POST', '/api/plan/presence', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['section' => $section], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function get(): array
    {
        $this->client->request('GET', '/api/plan/presence');
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function age(TelegramUser $user, int $seconds): void
    {
        $this->db->executeStatement(sprintf("UPDATE plan_presence SET last_seen_at = now() - interval '%d seconds' WHERE user_id = ?", $seconds), [$user->getId()]);
    }

    private function seenAt(TelegramUser $user): string
    {
        return (string) $this->db->fetchOne('SELECT last_seen_at FROM plan_presence WHERE user_id = ?', [$user->getId()]);
    }

    private function user(string $name, WorkRole $role, ?string $phone = null, bool $approved = true): TelegramUser
    {
        $user = new TelegramUser()->setPlanFullAccess(true)
            ->setTelegramId('presence-' . uniqid('', true))
            ->setFirstName($name)
            ->setLastName('Тест')
            ->setWorkRole($role)
            ->setPhoneNumber($phone);

        if ($approved) {
            $user->decideAccess(AccessStatus::Approved, null);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
