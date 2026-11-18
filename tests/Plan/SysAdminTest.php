<?php

namespace App\Tests\Plan;

use App\Supply\Repository\DepartmentRepository;
use App\Entity\TelegramUser;
use App\Plan\Event\CommentAdded;
use App\Plan\Notification\AudienceResolver;
use App\Plan\Subject\SubjectRef;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Repository\TaskRepository;
use App\Tasks\Service\TaskPolicy;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * «Системний адміністратор» (Олександр 17.11): усе, що адміністратор, але задач і «Звʼязку»
 * не бачить ніде — API, файли, пошук, Хроніка, Огляд, сповіщення — навіть де він учасник.
 */
class SysAdminTest extends WebTestCase
{
    use S4Fixtures;

    private const string PREFIX = 'sysadm-';

    public function testSysAdminSeesOnlyTasksWhereHeIsMemberLikeAnyUser(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $sys = $this->user(WorkRole::SysAdmin, 'sys');
        $colleague = $this->user(WorkRole::Production, 'col');
        $token = 'сисадмін' . random_int(10000, 99999);

        $this->login($manager);
        $mine = $this->send('POST', '/api/tasks', ['title' => 'Його задача ' . $token, 'memberIds' => [$sys->getId(), $colleague->getId()]]);
        self::assertResponseStatusCodeSame(201);
        $other = $this->send('POST', '/api/tasks', ['title' => 'Чужа задача ' . $token, 'memberIds' => [$colleague->getId()]]);
        $this->send('POST', '/api/tasks/' . $mine['id'] . '/messages', ['text' => 'Коментар ' . $token, 'mentions' => [$sys->getId()]]);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM task_member WHERE task_id = ? AND user_id = ?', [$mine['id'], $sys->getId()]), 'сисадміна можна додати учасником');

        $this->login($sys);
        $me = $this->get('/api/me');
        self::assertTrue($me['capabilities']['tasks.access'], 'задачі — як звичайний користувач');
        self::assertFalse($me['capabilities']['tasks.seeAll'], 'усі задачі заводу — ні');
        self::assertTrue($me['capabilities']['roles.manage']);
        self::assertTrue($me['capabilities']['staff.join']);
        self::assertFalse($me['capabilities']['audit.view'], 'журнал аудиту — лише суперадмін (у ньому уривки задач)');
        self::assertTrue($me['zvyazok'], 'чати — як звичайний користувач (з повним доступом, поки «Звʼязок» не для всіх)');

        $this->client->request('GET', '/api/tasks/' . $mine['id']);
        self::assertResponseIsSuccessful('свою задачу бачить');
        $this->send('POST', '/api/tasks/' . $mine['id'] . '/messages', ['text' => 'Я тут']);
        self::assertResponseStatusCodeSame(201);
        // Чуже повідомлення у своїй задачі не видаляє — як звичайний користувач.
        $foreign = (int) $this->em->getConnection()->fetchOne("SELECT id FROM task_message WHERE task_id = ? AND author_id = ? AND kind = 'comment' LIMIT 1", [$mine['id'], $manager->getId()]);
        $this->send('DELETE', '/api/tasks/' . $mine['id'] . '/messages/' . $foreign);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode(), 'чуже повідомлення');
        $this->client->request('GET', '/api/tasks/' . $other['id']);
        self::assertResponseStatusCodeSame(403, 'чужу — ні, як звичайний користувач');

        $hits = array_column(array_column($this->search(['q' => $token])['hits'], 'subject'), 'id');
        self::assertContains($mine['id'], $hits);
        self::assertNotContains($other['id'], $hits, 'пошук — лише свої');
        $keys = array_map(static fn ($i) => $i['subject']['type'] . '-' . $i['subject']['id'], $this->get('/api/plan/chronicle', ['unread' => 0, 'limit' => 100])['items']);
        self::assertContains('task-' . $mine['id'], $keys);
        self::assertNotContains('task-' . $other['id'], $keys, 'Хроніка — лише свої');

        $policy = self::getContainer()->get(TaskPolicy::class);
        $repo = self::getContainer()->get(TaskRepository::class);
        self::assertTrue($policy->canView($sys, $repo->find($mine['id'])));
        self::assertFalse($policy->canView($sys, $repo->find($other['id'])));
        self::assertFalse($policy->canEdit($sys, $repo->find($other['id'])));
        $resolver = self::getContainer()->get(AudienceResolver::class);
        $recipients = array_map(static fn ($r) => $r->userId, $resolver->resolve(new CommentAdded(SubjectRef::task($mine['id']), $manager->getId(), refId: 1, excerpt: 'x', mentions: [(int) $sys->getId()])));
        self::assertContains($sys->getId(), $recipients, 'сповіщення про свою задачу — так');
        $recipients = array_map(static fn ($r) => $r->userId, $resolver->resolve(new CommentAdded(SubjectRef::task($other['id']), $manager->getId(), refId: 1, excerpt: 'x')));
        self::assertNotContains($sys->getId(), $recipients, 'про чужу — ні');
    }

    public function testSysAdminChatsOnlyAsMember(): void
    {
        $a = $this->user(WorkRole::Manager, 'zva');
        $b = $this->user(WorkRole::Manager, 'zvb');
        $sys = $this->user(WorkRole::SysAdmin, 'zvs');
        $this->login($a);
        $with = $this->send('POST', '/api/plan/zvyazok/chats', ['kind' => 'group', 'title' => 'З сисадміном', 'memberIds' => [$sys->getId()]]);
        self::assertResponseStatusCodeSame(201);
        $without = $this->send('POST', '/api/plan/zvyazok/chats', ['kind' => 'direct', 'userId' => $b->getId()]);

        $this->login($sys);
        self::assertSame([$with['id']], array_column($this->get('/api/plan/zvyazok/chats')['items'], 'id'));
        $this->client->request('GET', '/api/plan/zvyazok/chats/' . $without['id'] . '/messages');
        self::assertResponseStatusCodeSame(404, 'чужий чат — ні');
        $this->send('POST', '/api/plan/zvyazok/chats/' . $with['id'] . '/messages', ['text' => 'Привіт']);
        self::assertResponseStatusCodeSame(201);
        $this->em->getConnection()->executeStatement('DELETE FROM plan_chat WHERE id IN (?, ?)', [$with['id'], $without['id']]);
    }

    public function testReviewHolesAreClosed(): void
    {
        $super = $this->user(WorkRole::Production, 'sup3');
        $super->setSuperadmin(true);
        $supplyMgr = $this->user(WorkRole::Production, 'spl3', SupplyRole::Manager);
        $this->em->flush();

        // Менеджер постачання не блокує суперадміна / адміна «Плану» через розділ заявок.
        $this->login($supplyMgr);
        $this->send('POST', '/api/supply/users/' . $super->getId() . '/access', ['status' => 'rejected']);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertTrue($this->em->find(TelegramUser::class, $super->getId())->isApproved());
        // І роль у заявках більше не робить директором «Плану».
        $plain = $this->user(WorkRole::Production, 'pln3');
        $this->send('PATCH', '/api/supply/users/' . $plain->getId(), ['role' => 'director']);
        $this->em->clear();
        self::assertSame(WorkRole::Production, $this->em->find(TelegramUser::class, $plain->getId())->getWorkRole());
    }

    public function testSysAdminCannotEscalateToTasks(): void
    {
        // Тест без транзакції (S4Fixtures): якщо щось просочилось у права ролей — прибрати.
        $this->em->getConnection()->executeStatement('DELETE FROM plan_role_permission');
        $sys = $this->user(WorkRole::SysAdmin, 'sys2');
        $worker = $this->user(WorkRole::Production, 'wrk2');
        $this->login($sys);

        // Свою роль — ні; ролі, що бачать усі задачі, — ні; звичайні — так.
        $this->send('POST', '/api/plan/roles/people/' . $sys->getId(), ['role' => 'admin']);
        self::assertResponseStatusCodeSame(403);
        foreach (['admin', 'director'] as $role) {
            $this->send('POST', '/api/plan/roles/people/' . $worker->getId(), ['role' => $role]);
            self::assertResponseStatusCodeSame(403, $role);
        }
        $this->send('POST', '/api/plan/roles/people/' . $worker->getId(), ['role' => 'manager']);
        self::assertResponseIsSuccessful();
        // Те саме через картку співробітника.
        $this->send('PATCH', '/api/staff/' . $worker->getId(), ['role' => 'director']);
        self::assertResponseStatusCodeSame(403);

        // Права на задачі він не роздає; свої зафіксовані клітинки — не змінити.
        $this->send('POST', '/api/plan/roles/permission', ['role' => 'production', 'permission' => 'tasks.seeAll', 'allowed' => true]);
        self::assertResponseStatusCodeSame(403);
        $this->send('POST', '/api/plan/roles/permission', ['role' => 'sysadmin', 'permission' => 'tasks.access', 'allowed' => true]);
        self::assertResponseStatusCodeSame(403);
        $this->send('POST', '/api/plan/roles/permission', ['role' => 'sysadmin', 'permission' => 'zvyazok.use', 'allowed' => true]);
        self::assertResponseStatusCodeSame(403);
        $this->send('POST', '/api/plan/roles/superadmin', ['userId' => $sys->getId(), 'grant' => true]);
        self::assertResponseStatusCodeSame(403);
        // «Веде людей» іншим ролям — лише суперадмін; ліди — не сисадміну (не бачить усіх задач).
        foreach (['staff.manage', 'leads.receive'] as $perm) {
            $this->send('POST', '/api/plan/roles/permission', ['role' => 'production', 'permission' => $perm, 'allowed' => true]);
            self::assertResponseStatusCodeSame(403, $perm);
        }
        // Телефон картки, яка бачить задачі, сисадмін не переписує (захоплення входу).
        $this->send('PATCH', '/api/staff/' . $worker->getId(), ['phone' => '+380671112299']);
        self::assertResponseStatusCodeSame(403);
        $this->send('PATCH', '/api/staff/' . $worker->getId(), ['role' => 'sysadmin', 'phone' => '+380671112299']);
        self::assertResponseStatusCodeSame(403, 'роль і телефон одним запитом — теж ні');
        // Свій відділ — ні (задача з відділом додала б його учасником чужих задач).
        $dep = self::getContainer()->get(DepartmentRepository::class)->findActive()[0] ?? null;
        if ($dep !== null) {
            $this->send('PATCH', '/api/staff/' . $sys->getId(), ['departmentId' => $dep->getId()]);
            self::assertResponseStatusCodeSame(403, 'свій відділ');
        }
        self::assertFalse($this->get('/api/me')['capabilities']['tasks.seeAll'], 'усі задачі — так і не відкрились');
    }
}
