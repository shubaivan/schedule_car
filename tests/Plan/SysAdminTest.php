<?php

namespace App\Tests\Plan;

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

    public function testSysAdminSeesNoTasksAnywhereEvenAsMember(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $sys = $this->user(WorkRole::SysAdmin, 'sys');
        $colleague = $this->user(WorkRole::Production, 'col');
        $token = 'сисадмін' . random_int(10000, 99999);

        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Задача ' . $token, 'memberIds' => [$sys->getId(), $colleague->getId()]]);
        self::assertResponseStatusCodeSame(201);
        $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'Коментар ' . $token, 'mentions' => [$sys->getId()]]);
        self::assertGreaterThan(0, $this->search(['q' => $token])['total'], 'менеджер знаходить свою задачу');
        $chronicleKeys = static fn (array $items) => array_map(static fn ($i) => $i['subject']['type'] . '-' . $i['subject']['id'], $items);
        self::assertContains('task-' . $task['id'], $chronicleKeys($this->get('/api/plan/chronicle', ['unread' => 0, 'limit' => 100])['items']), 'контроль: у менеджера задача в Хроніці');

        $this->login($sys);
        $me = $this->get('/api/me');
        self::assertFalse($me['capabilities']['tasks.access']);
        self::assertTrue($me['capabilities']['roles.manage']);
        self::assertTrue($me['capabilities']['staff.join'], 'заявки на приєднання — налаштування доступу');
        self::assertFalse($me['capabilities']['audit.view'], 'журнал аудиту — лише суперадмін (у ньому уривки задач)');
        self::assertFalse($me['zvyazok'], 'чати — ні, навіть з повним доступом');

        foreach (['/api/tasks/' . $task['id'], '/api/tasks/board', '/api/tasks/list', '/api/tasks/dashboard', '/api/tasks/' . $task['id'] . '/messages', '/api/tasks/chronicle', '/api/plan/zvyazok/chats', '/api/plan/zvyazok/unread'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $this->send('POST', '/api/tasks/' . $task['id'] . '/messages', ['text' => 'я тут']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/tasks/meta');
        self::assertResponseIsSuccessful('довідники людей і відділів — для сторінок «Люди» й «Ролі»');

        self::assertSame(0, $this->search(['q' => $token])['total'], 'пошук — без задач');
        self::assertNotContains('task-' . $task['id'], $chronicleKeys($this->get('/api/plan/chronicle', ['unread' => 0, 'limit' => 100])['items']), 'Хроніка — без задач');
        $overview = $this->get('/api/plan/overview');
        self::assertSame(0, $overview['kpi']['openTasks']);
        self::assertSame(0, $overview['replies']['total'], 'згадку в задачі не показуємо');

        $notifications = $this->get('/api/plan/notifications');
        self::assertSame([], array_values(array_filter($notifications['items'] ?? [], static fn ($n) => ($n['subject']['type'] ?? null) === 'task')), 'сповіщень про задачі немає');

        $policy = self::getContainer()->get(TaskPolicy::class);
        $entity = self::getContainer()->get(TaskRepository::class)->find($task['id']);
        self::assertFalse($policy->canView($sys, $entity));
        self::assertFalse($policy->canEdit($sys, $entity));
        $resolver = self::getContainer()->get(AudienceResolver::class);
        $recipients = array_map(static fn ($r) => $r->userId, $resolver->resolve(new CommentAdded(SubjectRef::task($task['id']), $manager->getId(), refId: 1, excerpt: 'x', mentions: [(int) $sys->getId()])));
        self::assertContains($colleague->getId(), $recipients, 'контроль: учасник отримує');
        self::assertNotContains($sys->getId(), $recipients, 'сповіщення про задачу системному адміну не йде навіть зі згадкою');
    }

    public function testReviewHolesAreClosed(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr3');
        $sys = $this->user(WorkRole::SysAdmin, 'sys3');
        $super = $this->user(WorkRole::Production, 'sup3');
        $super->setSuperadmin(true);
        $supplyMgr = $this->user(WorkRole::Production, 'spl3', SupplyRole::Manager);
        $this->em->flush();

        // Учасником системного адміна не додати.
        $this->login($manager);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Без сисадміна', 'memberIds' => [$sys->getId()]]);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM task_member WHERE task_id = ? AND user_id = ?', [$task['id'], $sys->getId()]));

        // URL-кодування шлях не обходить; задачу не створити.
        $this->login($sys);
        foreach (['GET /api/%74asks/chronicle/counts', 'POST /api/%74asks', 'GET /api/plan/%7Avyazok/chats'] as $call) {
            [$method, $url] = explode(' ', $call);
            $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{"title":"x"}');
            self::assertResponseStatusCodeSame(404, $call);
        }

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
        // «Веде людей» іншим ролям — лише суперадмін; «Звʼязок» і ліди — не сисадміну.
        foreach (['staff.manage', 'zvyazok.use', 'leads.receive'] as $perm) {
            $this->send('POST', '/api/plan/roles/permission', ['role' => 'production', 'permission' => $perm, 'allowed' => $perm !== 'zvyazok.use']);
            self::assertResponseStatusCodeSame(403, $perm);
        }
        // Телефон картки, яка бачить задачі, сисадмін не переписує (захоплення входу).
        $this->send('PATCH', '/api/staff/' . $worker->getId(), ['phone' => '+380671112299']);
        self::assertResponseStatusCodeSame(403);
        $this->send('PATCH', '/api/staff/' . $worker->getId(), ['role' => 'sysadmin', 'phone' => '+380671112299']);
        self::assertResponseStatusCodeSame(403, 'роль і телефон одним запитом — теж ні');
        self::assertFalse($this->get('/api/me')['capabilities']['tasks.access']);
    }
}
