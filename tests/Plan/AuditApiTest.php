<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Event\CommentAdded;
use App\Plan\Event\FileDownloaded;
use App\Plan\Event\PlanEvent;
use App\Plan\Event\StatusEvent;
use App\Plan\Event\SubjectCreated;
use App\Plan\Event\UserLoggedIn;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Tasks\Enum\WorkRole;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Приймання S6: журнал аудиту бачить лише суперадмін (403 на кожен маршрут API,
 * включно з CSV); фільтри працюють комбіновано; CSV віддає той самий зріз;
 * вхід, невдалий вхід і скачування файлу пишуться в журнал з actor, ip, каналом.
 */
class AuditApiTest extends WebTestCase
{
    private const string PREFIX = 'audit-test-';
    private const string PASSWORD = 'correct-horse-42';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $mark = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->mark = (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM plan_event');
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM plan_event WHERE id > ?', [$this->mark]);
        $ids = $conn->fetchFirstColumn('SELECT id FROM telegram_user WHERE telegram_id LIKE ?', [self::PREFIX . '%']);

        if ($ids !== []) {
            $list = implode(',', array_map('intval', $ids));
            $conn->executeStatement("DELETE FROM task WHERE creator_id IN ($list)");
            $conn->executeStatement("DELETE FROM crm_login WHERE user_id IN ($list)");
            $conn->executeStatement("DELETE FROM telegram_user WHERE id IN ($list)");
        }

        $conn->executeStatement("DELETE FROM crm_login WHERE name IS NULL AND user_agent = 'audit-test-agent'");

        parent::tearDown();
    }

    public function testNonSuperadminIsDeniedEverywhereIncludingCsv(): void
    {
        $director = $this->user(WorkRole::Director, 'dir');
        $id = $this->import(new SubjectCreated(SubjectRef::user((int) $director->getId()), $director->getId(), title: 'x'));

        $this->client->loginUser($director);

        foreach (['/api/plan/audit', '/api/plan/audit/meta', '/api/plan/audit/' . $id, '/api/plan/audit.csv'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->client->request('GET', '/api/me');
        self::assertFalse($this->json()['capabilities']['audit.view']);

        // Архівний суперадмін — теж ні: роль діє лише для активної людини.
        $gone = $this->user(WorkRole::Admin, 'gone', superadmin: true);
        $gone->archive();
        $this->em->flush();
        $this->client->loginUser($gone);
        $this->client->request('GET', '/api/plan/audit');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSuperadminSeesEverythingAndFiltersCombine(): void
    {
        $root = $this->user(WorkRole::Production, 'root', superadmin: true);
        $anna = $this->user(WorkRole::Manager, 'anna');
        $boris = $this->user(WorkRole::Manager, 'boris');
        $at = static fn (string $v) => new DateTimeImmutable($v, new DateTimeZone('Europe/Kyiv'));

        $task = 900000001;
        $comment = $this->import(new CommentAdded(SubjectRef::task($task), $anna->getId(), refId: 1, excerpt: 'Перевірте арматуру Ø12', at: $at('2030-03-02 10:00:00')), 'web', '10.1.1.5');
        $status = $this->import(StatusEvent::for(SubjectRef::supply(900000002), $boris->getId(), 'new', 'approval', closes: false, asksApproval: true, comment: 'Дорого, торгуйтесь', at: $at('2030-03-03 11:00:00')), 'web', '10.2.2.7');
        $statusNoComment = $this->import(StatusEvent::for(SubjectRef::task($task), $boris->getId(), 'new', 'in_work', closes: false, asksApproval: false, at: $at('2030-03-03 12:00:00')));
        $login = $this->import(new UserLoggedIn(SubjectRef::user((int) $anna->getId()), $anna->getId(), method: 'password', userAgent: 'UA', at: $at('2030-03-04 09:00:00')), 'web', '10.1.1.5');
        $download = $this->import(new FileDownloaded(SubjectRef::task($task), $boris->getId(), fileId: 7, fileType: 'task', name: 'Кошторис.pdf', at: $at('2030-03-05 15:00:00')), 'web', '10.2.2.7');

        $this->client->loginUser($root);

        // Без фільтра — усе, найновіше зверху (суперадмін-виробничник бачить чужі задачі й входи).
        $ids = $this->ids('from=2030-03-01&to=2030-03-31');
        self::assertSame([$download, $login, $statusNoComment, $status, $comment], $ids);

        self::assertSame([$login, $comment], $this->ids('from=2030-03-01&actor=' . $anna->getId()));
        self::assertSame([$login], $this->ids('from=2030-03-01&actor=' . $anna->getId() . '&group=login'));
        self::assertSame([$download], $this->ids('from=2030-03-01&group=access&ip=10.2.'));
        self::assertSame([$download, $statusNoComment, $comment], $this->ids('from=2030-03-01&subject=task-' . $task));
        self::assertSame([$status], $this->ids('from=2030-03-01&kind=supply'));
        self::assertSame([$statusNoComment, $status], $this->ids('from=2030-03-03&to=2030-03-03'));
        self::assertSame([$comment], $this->ids('from=2030-03-01&q=' . rawurlencode('арматуру')));
        self::assertSame([$login, $comment], $this->ids('from=2030-03-01&q=' . rawurlencode('Anna')));
        // type[] — масив; comment_added серед типів теж підтягує статус із коментарем.
        self::assertSame([$statusNoComment, $status], $this->ids('from=2030-03-01&type[]=status_changed&type[]=comment_added&actor=' . $boris->getId()));
        self::assertSame([$statusNoComment], $this->ids('from=2030-03-01&type=status_changed'));

        // «Коментарі» знаходять і окремий коментар, і коментар, що прийшов разом зі статусом (живий журнал).
        self::assertSame([$status, $comment], $this->ids('from=2030-03-01&group=comment'));
        $row = $this->page('from=2030-03-01&group=comment')['items'][0];
        self::assertSame('Дорого, торгуйтесь', $row['comment']);
        self::assertSame('approval_requested', $row['type']);
        self::assertSame('10.2.2.7', $row['ip']);
        self::assertSame('web', $row['channel']);
        self::assertSame($boris->getId(), $row['actor']['id']);

        // Сторінки за курсором: before — старіші, after — новіші.
        $first = $this->page('from=2030-03-01&limit=2');
        self::assertSame([$download, $login], array_column($first['items'], 'id'));
        self::assertSame(5, $first['total']);
        self::assertNull($first['newer']);
        $second = $this->page('from=2030-03-01&limit=2&before=' . $first['older']);
        self::assertSame([$statusNoComment, $status], array_column($second['items'], 'id'));
        self::assertSame($statusNoComment, $second['newer']);
        $back = $this->page('from=2030-03-01&limit=2&after=' . $second['newer']);
        self::assertSame([$download, $login], array_column($back['items'], 'id'));

        // Невідомий фільтр — 400, а не «усе».
        foreach (['group=nope', 'type[]=nope', 'kind=planet', 'subject=task', 'from=2030-13-01', 'from=2030-03-05&to=2030-03-01', 'actor=abc'] as $bad) {
            $this->client->request('GET', '/api/plan/audit?' . $bad);
            self::assertResponseStatusCodeSame(400, $bad);
        }

        $this->client->request('GET', '/api/me');
        self::assertTrue($this->json()['capabilities']['audit.view']);
        self::assertTrue($this->json()['capabilities']['tasks.seeAll']);
    }

    public function testEventDetailAndCsvExport(): void
    {
        $root = $this->user(WorkRole::Admin, 'root', superadmin: true);
        $anna = $this->user(WorkRole::Manager, 'anna');
        $task = 900000003;
        $created = $this->import(new SubjectCreated(SubjectRef::task($task), $anna->getId(), title: '=HYPERLINK("http://evil")'));
        $comment = $this->import(new CommentAdded(SubjectRef::task($task), $anna->getId(), refId: 5, excerpt: 'Привіт; "лапки"', mentions: [(int) $root->getId()]), 'web', '10.9.9.9');

        $this->client->loginUser($root);
        $detail = $this->get('/api/plan/audit/' . $comment);
        self::assertSame('comment_added', $detail['type']);
        self::assertSame('Привіт; "лапки"', $detail['payload']['excerpt']);
        self::assertSame([$comment, $created], array_column($detail['around'], 'id'));
        self::assertSame([$root->getId()], array_column($detail['people'], 'id'));

        $this->client->request('GET', '/api/plan/audit/999999999');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/plan/audit.csv?subject=task-' . $task);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}id;", $csv);
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));
        self::assertCount(3, $lines, 'заголовок + 2 рядки суб\'єкта');
        $rows = array_map(static fn (string $l) => str_getcsv($l, ';', '"', ''), $lines);
        self::assertSame((string) $comment, $rows[1][0]);
        self::assertSame('Привіт; "лапки"', $rows[1][8]);
        self::assertSame('10.9.9.9', $rows[1][11]);
        // Формула з даних у Excel не виконується.
        self::assertSame("'=HYPERLINK(\"http://evil\")", $rows[2][8]);
    }

    public function testLoginsAndFailedLoginsAreJournaled(): void
    {
        $user = $this->user(WorkRole::Manager, 'login');

        $this->client->request('GET', '/vhid');
        $token = $this->client->getCrawler()->filter('input[name=_csrf_token]')->attr('value');
        $this->client->request('POST', '/vhid', ['login' => $user->getEmail(), 'password' => 'wrong-password', '_csrf_token' => $token], [], ['HTTP_USER_AGENT' => 'audit-test-agent', 'REMOTE_ADDR' => '10.7.7.7']);
        self::assertResponseStatusCodeSame(401);

        $token = $this->client->getCrawler()->filter('input[name=_csrf_token]')->attr('value');
        $this->client->request('POST', '/vhid', ['login' => 'nobody-' . uniqid() . '@example.test', 'password' => 'x', '_csrf_token' => $token], [], ['HTTP_USER_AGENT' => 'audit-test-agent']);
        self::assertResponseStatusCodeSame(401);

        $token = $this->client->getCrawler()->filter('input[name=_csrf_token]')->attr('value');
        $this->client->request('POST', '/vhid', ['login' => $user->getEmail(), 'password' => self::PASSWORD, '_csrf_token' => $token], [], ['HTTP_USER_AGENT' => 'audit-test-agent', 'REMOTE_ADDR' => '10.7.7.8']);
        self::assertResponseRedirects();

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM plan_event WHERE id > ? ORDER BY id', [$this->mark]);
        self::assertSame(['login_failed', 'login_failed', 'user_logged_in'], array_column($rows, 'type'));

        [$wrong, $unknown, $ok] = $rows;
        $payload = static fn (array $r) => json_decode((string) $r['payload'], true);

        // Невірний пароль від відомого логіна — на ту людину; без актора (вхід не відбувся).
        self::assertSame(['user', $user->getId()], [$wrong['subject_type'], (int) $wrong['subject_id']]);
        self::assertNull($wrong['actor_id']);
        self::assertSame('10.7.7.7', $wrong['ip']);
        self::assertSame($user->getEmail(), $payload($wrong)['name']);
        self::assertSame(['user', 0], [$unknown['subject_type'], (int) $unknown['subject_id']]);

        self::assertSame($user->getId(), (int) $ok['actor_id']);
        self::assertSame('web', $ok['channel']);
        self::assertSame('10.7.7.8', $ok['ip']);
        self::assertSame('password', $payload($ok)['method']);
        self::assertSame('audit-test-agent', $payload($ok)['userAgent']);
        // refId = crm_login.id — за ним plan:events:backfill бачить, що вхід уже в журналі.
        $loginRow = (int) $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM crm_login WHERE user_id = ? AND success', [$user->getId()]);
        self::assertSame($loginRow, $payload($ok)['refId']);
        foreach ([$wrong, $unknown] as $failed) {
            self::assertIsInt($payload($failed)['refId']);
        }
    }

    public function testFileDownloadIsJournaledOncePerTenMinutes(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $other = $this->user(WorkRole::Director, 'dir');
        $this->client->loginUser($manager);

        $this->client->request('POST', '/api/tasks', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['title' => 'Аудит файлів']));
        self::assertResponseStatusCodeSame(201);
        $task = $this->json();

        $path = tempnam(sys_get_temp_dir(), 'au');
        file_put_contents($path, "кошторис\n");
        $this->client->request('POST', '/api/tasks/' . $task['id'] . '/messages', [], ['files' => [new UploadedFile($path, 'Кошторис.txt', 'text/plain', null, true)]]);
        self::assertResponseStatusCodeSame(201);
        $file = $this->json()['attachments'][0];
        $this->mark = (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM plan_event');

        $this->client->request('GET', $file['urls']['download']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $file['urls']['inline']);
        $this->client->request('GET', $file['urls']['download']);
        $this->client->request('GET', '/api/tasks/files/' . $file['id'] . '/thumb');

        $this->client->loginUser($other);
        $this->client->request('GET', $file['urls']['download']);
        self::assertResponseIsSuccessful();

        $rows = $this->em->getConnection()->fetchAllAssociative("SELECT * FROM plan_event WHERE id > ? AND type = 'file_downloaded' ORDER BY id", [$this->mark]);
        self::assertSame([$manager->getId(), $other->getId()], array_map(static fn (array $r) => (int) $r['actor_id'], $rows));
        self::assertSame(['task', (int) $task['id']], [$rows[0]['subject_type'], (int) $rows[0]['subject_id']]);
        $payload = json_decode((string) $rows[0]['payload'], true);
        self::assertEquals(['fileId' => $file['id'], 'fileType' => 'task', 'name' => 'Кошторис.txt', 'shareLinkId' => null], $payload);
        self::assertSame('web', $rows[0]['channel']);
    }

    public function testSuperadminOpensAnyTask(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $root = $this->user(WorkRole::Production, 'root', superadmin: true);
        $plain = $this->user(WorkRole::Production, 'plain');

        $this->client->loginUser($manager);
        $this->client->request('POST', '/api/tasks', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['title' => 'Чужа задача']));
        $task = $this->json();

        $this->client->loginUser($plain);
        $this->client->request('GET', '/api/tasks/' . $task['id']);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($root);
        $this->client->request('GET', '/api/tasks/' . $task['id']);
        self::assertResponseIsSuccessful();
    }

    private function import(PlanEvent $event, string $channel = 'web', ?string $ip = null): int
    {
        return self::getContainer()->get(PlanEventRecorder::class)->import($event->via($channel, $ip));
    }

    /** @return list<int> */
    private function ids(string $query): array
    {
        return array_column($this->page($query)['items'], 'id');
    }

    /** @return array<string, mixed> */
    private function page(string $query): array
    {
        return $this->get('/api/plan/audit?' . $query);
    }

    /** @return array<string, mixed> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function user(WorkRole $role, string $name, bool $superadmin = false): TelegramUser
    {
        $user = new TelegramUser()
            ->setTelegramId(self::PREFIX . $name . '-' . uniqid())
            ->setFirstName(ucfirst($name))
            ->setLastName('Аудит')
            ->setWorkRole($role)
            ->setSuperadmin($superadmin)
            ->setEmail(self::PREFIX . $name . uniqid() . '@example.test');
        $user->decideAccess(AccessStatus::Approved, null);
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
