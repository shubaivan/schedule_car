<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Access\RoleGrants;
use App\Tasks\Enum\WorkRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * «Ролі й доступ» (Олександр 17.11): керують лише адмін, системний адмін і суперадмін;
 * права ролей діють одразу й пишуться в аудит; зафіксовані клітинки не змінюються;
 * «Приховані розділи» — лише суперадмін; останнього суперадміна не зняти.
 */
class RolesApiTest extends WebTestCase
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
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        RoleGrants::forget();
        parent::tearDown();
    }

    public function testOnlyRoleManagersSeeThePage(): void
    {
        foreach ([WorkRole::Director, WorkRole::Manager, WorkRole::Accountant, WorkRole::Production] as $role) {
            $this->client->loginUser($this->user($role));
            $this->call('GET', '/api/plan/roles');
            self::assertResponseStatusCodeSame(404, $role->value);
            $this->call('POST', '/api/plan/roles/permission', ['role' => 'production', 'permission' => 'tasks.seeAll', 'allowed' => true]);
            self::assertResponseStatusCodeSame(404, $role->value);
        }

        foreach ([WorkRole::Admin, WorkRole::SysAdmin] as $role) {
            $this->client->loginUser($this->user($role));
            $page = $this->call('GET', '/api/plan/roles');
            self::assertResponseIsSuccessful($role->value);
            self::assertContains('sysadmin', array_column($page['roles'], 'value'));
            self::assertNotEmpty($page['people']);
        }

        // Запис — лише JSON.
        $this->client->request('POST', '/api/plan/roles/permission', ['role' => 'production', 'permission' => 'tasks.seeAll', 'allowed' => '1']);
        self::assertResponseStatusCodeSame(415);
    }

    public function testPermissionChangeAppliesAuditsAndRevertsToDefault(): void
    {
        $admin = $this->user(WorkRole::Admin);
        $worker = $this->user(WorkRole::Production);
        $manager = $this->user(WorkRole::Manager);
        $this->client->loginUser($manager);
        $task = $this->call('POST', '/api/tasks', ['title' => 'Чужа задача']);

        $this->client->loginUser($worker);
        $this->call('GET', '/api/tasks/' . $task['id']);
        self::assertResponseStatusCodeSame(403, 'виробництво чужих задач не бачить');

        $this->client->loginUser($admin);
        $page = $this->call('POST', '/api/plan/roles/permission', ['role' => 'production', 'permission' => 'tasks.seeAll', 'allowed' => true]);
        self::assertResponseIsSuccessful();
        $cell = $this->cell($page, 'tasks.seeAll', 'production');
        self::assertTrue($cell['allowed']);
        self::assertTrue($cell['changed']);
        self::assertStringContainsString('Виробництво · Бачить усі задачі заводу: дозволено', $page['history'][0]['text']);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM plan_event WHERE type = 'role_permission_changed' AND actor_id = ?", [$admin->getId()]));

        $this->client->loginUser($worker);
        $this->call('GET', '/api/tasks/' . $task['id']);
        self::assertResponseIsSuccessful('право діє з наступного запиту');

        // Назад як у коді — рядка в таблиці не лишається.
        $this->client->loginUser($admin);
        $page = $this->call('POST', '/api/plan/roles/permission', ['role' => 'production', 'permission' => 'tasks.seeAll', 'allowed' => false]);
        self::assertFalse($this->cell($page, 'tasks.seeAll', 'production')['changed']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_role_permission'));
        $this->client->loginUser($worker);
        $this->call('GET', '/api/tasks/' . $task['id']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testLockedCellsSectionsAndRoleAssignmentGuards(): void
    {
        $admin = $this->user(WorkRole::Admin);
        $otherAdmin = $this->user(WorkRole::Admin);
        $worker = $this->user(WorkRole::Production);
        $this->client->loginUser($admin);

        foreach ([['admin', 'staff.manage', false], ['sysadmin', 'tasks.access', true], ['sysadmin', 'staff.manage', false]] as [$role, $permission, $allowed]) {
            $out = $this->call('POST', '/api/plan/roles/permission', ['role' => $role, 'permission' => $permission, 'allowed' => $allowed]);
            self::assertResponseStatusCodeSame(403, "$role $permission");
            self::assertNotEmpty($out['error']);
        }

        // «Приховані розділи» ролі — лише суперадмін (як і прапорець людини).
        $this->call('POST', '/api/plan/roles/permission', ['role' => 'manager', 'permission' => 'sections.full', 'allowed' => true]);
        self::assertResponseStatusCodeSame(403);

        // Роль: свою — ні, колезі-адміну — ні, робітнику — так (і в аудит).
        $this->call('POST', '/api/plan/roles/people/' . $admin->getId(), ['role' => 'director']);
        self::assertResponseStatusCodeSame(403);
        $this->call('POST', '/api/plan/roles/people/' . $otherAdmin->getId(), ['role' => 'manager']);
        self::assertResponseStatusCodeSame(403);
        $page = $this->call('POST', '/api/plan/roles/people/' . $worker->getId(), ['role' => 'sysadmin']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Виробництво → Системний адміністратор', $page['history'][0]['text']);
        $row = array_first(array_filter($page['people'], static fn ($p) => $p['id'] === $worker->getId()));
        self::assertSame('sysadmin', $row['role']);
        self::assertSame([], array_first(array_filter($page['people'], static fn ($p) => $p['id'] === $admin->getId()))['assignable'], 'свою роль адмін не змінює');

        // Суперадмін — може «Приховані розділи».
        $super = $this->user(WorkRole::Production, superadmin: true);
        $this->client->loginUser($super);
        $page = $this->call('POST', '/api/plan/roles/permission', ['role' => 'manager', 'permission' => 'sections.full', 'allowed' => true]);
        self::assertResponseIsSuccessful();
        $sales = $this->user(WorkRole::Manager, full: false);
        $this->client->loginUser($sales);
        self::assertTrue($this->call('GET', '/api/me')['fullAccess'], 'роль відкрила приховані розділи');
    }

    public function testSuperadminsLastOneStays(): void
    {
        // Інші суперадміни бази тесту (якщо є) не мають заважати: знімаємо всіх, крім нашого.
        $this->db->executeStatement('UPDATE telegram_user SET is_superadmin = FALSE');
        $super = $this->user(WorkRole::Production, superadmin: true);
        $admin = $this->user(WorkRole::Admin);
        $next = $this->user(WorkRole::Director);

        $this->client->loginUser($admin);
        $this->call('POST', '/api/plan/roles/superadmin', ['userId' => $next->getId(), 'grant' => true]);
        self::assertResponseStatusCodeSame(403, 'адмін суперадмінів не призначає');

        $this->client->loginUser($super);
        $this->call('POST', '/api/plan/roles/superadmin', ['userId' => $super->getId(), 'grant' => false]);
        self::assertResponseStatusCodeSame(403, 'себе — ні');
        $page = $this->call('POST', '/api/plan/roles/superadmin', ['userId' => $next->getId(), 'grant' => true]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('видано права суперадміністратора', $page['history'][0]['text']);

        // Другий знімає першого — лишається один; останнього не зняти.
        $next = $this->em->find(TelegramUser::class, $next->getId());
        $this->client->loginUser($next);
        $this->call('POST', '/api/plan/roles/superadmin', ['userId' => $super->getId(), 'grant' => false]);
        self::assertResponseIsSuccessful();
        $this->call('POST', '/api/plan/roles/superadmin', ['userId' => $next->getId(), 'grant' => false]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM telegram_user WHERE is_superadmin = TRUE'));
        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM plan_event WHERE type = 'staff_changed' AND payload->>'action' LIKE 'superadmin_%'"));
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>
     */
    private function cell(array $page, string $permission, string $role): array
    {
        $row = array_first(array_filter($page['permissions'], static fn ($p) => $p['value'] === $permission));

        return array_first(array_filter($row['cells'], static fn ($c) => $c['role'] === $role));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $url, array $body = []): array
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: $method === 'GET' ? null : json_encode($body, JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function user(WorkRole $role, bool $superadmin = false, bool $full = true): TelegramUser
    {
        $user = new TelegramUser()
            ->setTelegramId('roles-' . uniqid('', true))
            ->setFirstName('Тест ' . $role->value)
            ->setLastName('Ролі')
            ->setWorkRole($role)
            ->setPlanFullAccess($full)
            ->setSuperadmin($superadmin);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
