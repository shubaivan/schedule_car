<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Entity\Department;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\TaskService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Правило Олександра 15.11.2026: «Мої повідомлення» — я створив, я писав або до мене
 * звернулись (з лічильником непрочитаних); «Хроніка» — усі задачі, де я присутній;
 * хто не присутній у задачі (і не рівня адміна) — не бачить її ніде.
 */
class TaskPresenceTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private TaskService $tasks;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->tasks = self::getContainer()->get(TaskService::class);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testMyMessagesAreCreatedWrittenOrAddressedWithUnreadCount(): void
    {
        $me = $this->user(WorkRole::Manager);
        $boss = $this->user(WorkRole::Director);

        $created = $this->tasks->create($me, ['title' => 'Я створив']);
        $written = $this->tasks->create($boss, ['title' => 'Я писав', 'memberIds' => [$me->getId()]]);
        $addressed = $this->tasks->create($boss, ['title' => 'До мене звернулись']);
        $justMember = $this->tasks->create($boss, ['title' => 'Лише учасник', 'memberIds' => [$me->getId()]]);

        $this->tasks->post($written, $me, 'Моє повідомлення');
        $this->tasks->post($written, $boss, 'Відповідь 1');
        $this->tasks->post($written, $boss, 'Відповідь 2');
        $this->tasks->post($addressed, $boss, 'Питання до тебе', [], [(int) $me->getId()]);
        $this->tasks->post($justMember, $boss, 'Без відмітки');

        $this->client->loginUser($me);
        $board = $this->get('/api/tasks/board');
        $messages = array_column($board['messages'], null, 'id');

        self::assertArrayHasKey($created->getId(), $messages);
        self::assertArrayHasKey($written->getId(), $messages);
        self::assertArrayHasKey($addressed->getId(), $messages);
        self::assertArrayNotHasKey($justMember->getId(), $messages, 'учасник без власних повідомлень і відміток — не «мої повідомлення»');
        self::assertSame(2, $messages[$written->getId()]['unread'], 'дві відповіді після мого повідомлення — непрочитані');
        self::assertSame(2, $messages[$addressed->getId()]['unread'], 'подія «задачу створено» + питання — як і всюди, «+N» рахує й події');

        // Прочитав — лічильник згас.
        $this->get('/api/tasks/' . $written->getId());
        $messages = array_column($this->get('/api/tasks/board')['messages'], null, 'id');
        self::assertSame(0, $messages[$written->getId()]['unread']);
    }

    public function testChronicleColumnListsEveryTaskImPresentInIncludingViaDepartment(): void
    {
        $dep = $this->department();
        $me = $this->user(WorkRole::Production, $dep);
        $author = $this->user(WorkRole::Manager);
        $director = $this->user(WorkRole::Director);

        $viaDep = $this->tasks->create($author, ['title' => 'Через відділ', 'departmentIds' => [$dep->getId()]]);
        $member = $this->tasks->create($author, ['title' => 'Учасник', 'memberIds' => [$me->getId()]]);
        $foreign = $this->tasks->create($author, ['title' => 'Чужа']);

        $this->client->loginUser($me);
        $ids = array_column($this->get('/api/tasks/board')['chronicle'], 'id');
        self::assertContains($viaDep->getId(), $ids, 'відкривати задачу не треба — присутність достатня');
        self::assertContains($member->getId(), $ids);
        self::assertNotContains($foreign->getId(), $ids);

        // Директор (рівень адміна) — бачить усе, як і було.
        $this->client->loginUser($director);
        self::assertContains($foreign->getId(), array_column($this->get('/api/tasks/board')['chronicle'], 'id'));
        $this->client->request('GET', '/api/tasks/' . $foreign->getId());
        self::assertResponseIsSuccessful();
    }

    public function testOutsiderSeesTheTaskNowhere(): void
    {
        $token = 'Таємниця' . random_int(100000, 999999);
        $author = $this->user(WorkRole::Manager);
        $insider = $this->user(WorkRole::Production);
        $outsider = $this->user(WorkRole::Manager);
        $task = $this->tasks->create($author, ['title' => $token . ' угода', 'client' => $token, 'memberIds' => [$insider->getId()]]);
        $message = $this->tasks->post($task, $author, $token . ' коментар', [], [(int) $insider->getId()]);
        $id = (int) $task->getId();

        // Учасник бачить — перевірка, що запити справді знаходять задачу.
        $this->client->loginUser($insider);
        $this->client->request('GET', '/api/tasks/' . $id);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($token, $this->body('/api/tasks/board'));

        $this->client->loginUser($outsider);
        foreach (['/api/tasks/' . $id, '/api/tasks/' . $id . '/messages'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        foreach ([
            ['POST', '/api/tasks/' . $id . '/messages', ['text' => 'хочу зайти']],
            ['POST', '/api/tasks/' . $id . '/status', ['to' => 'in_work']],
            ['PATCH', '/api/tasks/' . $id, ['title' => 'зламав']],
            ['POST', '/api/tasks/' . $id . '/read', []],
            ['POST', '/api/tasks/' . $id . '/favorite', ['on' => true]],
            ['POST', '/api/tasks/' . $id . '/messages/' . $message->getId() . '/pin', []],
            ['POST', '/api/tasks/' . $id . '/messages/' . $message->getId() . '/star', []],
        ] as [$method, $url, $body]) {
            $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));
            self::assertResponseStatusCodeSame(403, $method . ' ' . $url);
        }

        foreach ([
            '/api/tasks/board',
            '/api/tasks/list',
            '/api/tasks/list?f=mine',
            '/api/tasks/dashboard',
            '/api/tasks/search?q=' . urlencode($token),
            '/api/tasks/chronicle',
            '/api/plan/chronicle',
            '/api/plan/search?q=' . urlencode($token),
            '/api/plan/overview',
            '/api/plan/notifications',
        ] as $url) {
            self::assertStringNotContainsString($token, $this->body($url), $url);
        }

        // Перевірка не порожня: те саме для учасника — знаходить.
        $this->client->loginUser($insider);
        self::assertStringContainsString($token, $this->body('/api/plan/overview'), 'учаснику — у «Чекають моєї відповіді»');
    }

    private function body(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array<string, mixed> */
    private function get(string $url): array
    {
        return (array) json_decode($this->body($url), true);
    }

    private function user(WorkRole $role, ?Department $department = null): TelegramUser
    {
        $user = new TelegramUser()->setPlanFullAccess(true)
            ->setTelegramId('presence-' . uniqid('', true))
            ->setFirstName('Присутність')
            ->setLastName($role->label())
            ->setWorkRole($role)
            ->setDepartment($department);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function department(): Department
    {
        $department = new Department()->setName('Відділ присутності ' . uniqid());
        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }
}
