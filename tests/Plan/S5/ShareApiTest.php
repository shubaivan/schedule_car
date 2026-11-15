<?php

namespace App\Tests\Plan\S5;

use App\Plan\Drive\DriveException;
use App\Plan\Drive\GoogleDriveClient;
use App\Plan\Share\Entity\ShareLink;
use App\Plan\Share\ShareService;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Enum\WorkRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Google\Service\Exception as GoogleException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * S5 «поділитися»: з людьми (лише з тими, хто має доступ) → сповіщення; посилання
 * /s/{token}: працює до expires_at, після — 410; відкликання; автор утратив доступ —
 * посилання мертве; у базі лише хеш; сторінка Диска — лише адміну.
 */
class ShareApiTest extends WebTestCase
{
    use S5Fixtures;

    private KernelBrowser $client;
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
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }

        parent::tearDown();
    }

    public function testShareWithPeopleOnlyWhoCanSeeTheFile(): void
    {
        $author = $this->person(WorkRole::Manager, 'author');
        $member = $this->person(WorkRole::Production, 'member');
        $outsider = $this->person(WorkRole::Accountant, 'outsider');
        $file = $this->taskFile($this->task($author, 'Креслення', [$member]), $author);
        $url = '/api/plan/files/task/' . $file->getId();

        $this->client->loginUser($author);
        $people = $this->get($url . '/recipients')['people'];
        $flags = array_column($people, 'canView', 'id');
        self::assertTrue($flags[$member->getId()]);
        self::assertFalse($flags[$outsider->getId()]);

        $this->post($url . '/share', ['userIds' => [$outsider->getId()]]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($outsider->shortName(), $this->json()['error']);

        $this->post($url . '/share', ['userIds' => [$member->getId()], 'note' => 'Глянь розміри']);
        self::assertResponseStatusCodeSame(201);

        $event = $this->db->fetchAssociative("SELECT id, payload FROM plan_event WHERE type = 'share_created' ORDER BY id DESC LIMIT 1");
        self::assertIsArray($event);
        $payload = json_decode((string) $event['payload'], true);
        self::assertSame([$member->getId()], $payload['userIds']);
        self::assertSame('users', $payload['via']);
        self::assertSame('Глянь розміри', $payload['note']);
        self::assertSame([$member->getId()], array_map('intval', $this->db->fetchFirstColumn('SELECT user_id FROM notification WHERE event_id = ?', [$event['id']])));
        self::assertSame(['share'], $this->db->fetchFirstColumn('SELECT event_group FROM notification WHERE event_id = ?', [$event['id']]));

        // Чужий файл не бачить узагалі — ні опису, ні «поділитися».
        $this->client->loginUser($outsider);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
        $this->post($url . '/share', ['userIds' => [$member->getId()]]);
        self::assertResponseStatusCodeSame(404);
        $this->post($url . '/links', []);
        self::assertResponseStatusCodeSame(404);
    }

    public function testFileMetaForReader(): void
    {
        $author = $this->person(WorkRole::Manager, 'author');
        $file = $this->taskFile($this->task($author, 'Опис'), $author, 'схема.txt');

        $this->client->loginUser($author);
        $meta = $this->get('/api/plan/files/task/' . $file->getId());

        self::assertSame('схема.txt', $meta['file']['name']);
        self::assertSame('task', $meta['file']['fileType']);
        self::assertSame('pending', $meta['drive']['state']);
        self::assertSame(['name' => 'tasks-board', 'params' => ['id' => (string) $file->getTask()->getId()]], $meta['subject']['route']);

        $this->client->request('GET', '/api/plan/files/task/' . $file->getId() . '/download');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM plan_event WHERE type = 'file_downloaded' AND actor_id = ? AND (payload->>'fileId')::int = ?", [$author->getId(), $file->getId()]));
    }

    public function testPreviewInViewerIsNotLoggedAsDownload(): void
    {
        $author = $this->person(WorkRole::Manager, 'author');
        $file = $this->taskFile($this->task($author, 'Перегляд'), $author, 'фото.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        $this->client->loginUser($author);
        $this->client->request('GET', '/api/plan/files/task/' . $file->getId() . '/inline');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('inline', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM plan_event WHERE type = 'file_downloaded' AND actor_id = ?", [$author->getId()]), 'Перегляд — не «Файл скачано»');
    }

    public function testLinkWorksUntilExpiryThenGone(): void
    {
        $author = $this->person(WorkRole::Manager, 'author');
        $file = $this->taskFile($this->task($author, 'Посилання'), $author, 'прайс.txt', 'ціни');

        $this->client->loginUser($author);
        $link = $this->post('/api/plan/files/task/' . $file->getId() . '/links', []);
        self::assertResponseStatusCodeSame(201);
        self::assertMatchesRegularExpression('#/s/[A-Za-z0-9_-]{32}$#', $link['url']);
        $token = substr($link['url'], -32);

        // У базі лише хеш, не сам токен.
        $stored = $this->db->fetchOne('SELECT token_hash FROM share_link WHERE id = ?', [$link['id']]);
        self::assertSame(hash('sha256', $token), $stored);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM share_link WHERE token_hash = ?', [$token]));
        $days = (strtotime($link['expiresAt']) - strtotime($link['createdAt'])) / 86400;
        self::assertEqualsWithDelta(ShareService::TTL_DAYS, $days, 0.01);

        // Зовнішня людина — без сесії.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/s/' . $token);
        self::assertResponseIsSuccessful();
        $headers = $this->client->getResponse()->headers;
        self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('прайс.txt', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT downloads FROM share_link WHERE id = ?', [$link['id']]), 'сторінка (прев\'ю месенджера) не скачує');

        $this->client->request('GET', '/s/' . $token . '/download');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT downloads FROM share_link WHERE id = ?', [$link['id']]));
        $download = $this->db->fetchAssociative("SELECT actor_id, payload FROM plan_event WHERE type = 'file_downloaded' ORDER BY id DESC LIMIT 1");
        self::assertNull($download['actor_id']);
        self::assertSame($link['id'], json_decode((string) $download['payload'], true)['shareLinkId']);

        // Картинкою прев'ю віддається лише для фото.
        $this->client->request('GET', '/s/' . $token . '/view');
        self::assertResponseStatusCodeSame(410);

        // Межа строку — у київському настінному часі, як і всі дати в базі.
        $this->db->executeStatement('UPDATE share_link SET expires_at = ? WHERE id = ?', [ShareLink::now()->modify('+2 minutes')->format('Y-m-d H:i:s'), $link['id']]);
        $this->client->request('GET', '/s/' . $token);
        self::assertResponseIsSuccessful('за 2 хвилини до кінця строку ще працює');
        $this->db->executeStatement('UPDATE share_link SET expires_at = ? WHERE id = ?', [ShareLink::now()->modify('-1 minute')->format('Y-m-d H:i:s'), $link['id']]);
        $this->client->request('GET', '/s/' . $token);
        self::assertResponseStatusCodeSame(410);
        self::assertStringContainsString('Термін дії', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/s/' . $token . '/download');
        self::assertResponseStatusCodeSame(410);
    }

    public function testRevokeOnlyByAuthorOrAdmin(): void
    {
        $author = $this->person(WorkRole::Manager, 'author');
        $member = $this->person(WorkRole::Production, 'member');
        $admin = $this->person(WorkRole::Admin, 'admin', SupplyRole::Admin);
        $file = $this->taskFile($this->task($author, 'Відкликання', [$member]), $author);

        $this->client->loginUser($author);
        $link = $this->post('/api/plan/files/task/' . $file->getId() . '/links', []);
        $token = substr($link['url'], -32);

        // Учасник бачить файл, але чужого посилання не бачить і не відкликає.
        $this->client->loginUser($member);
        self::assertSame([], $this->get('/api/plan/files/task/' . $file->getId() . '/links')['links']);
        $this->post('/api/plan/share-links/' . $link['id'] . '/revoke', []);
        self::assertResponseStatusCodeSame(404);

        // Адмін бачить усі активні посилання файлу.
        $this->client->loginUser($admin);
        self::assertSame([$link['id']], array_column($this->get('/api/plan/files/task/' . $file->getId() . '/links')['links'], 'id'));

        $this->client->loginUser($author);
        $revoked = $this->post('/api/plan/share-links/' . $link['id'] . '/revoke', []);
        self::assertResponseIsSuccessful();
        self::assertNotNull($revoked['revokedAt']);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM plan_event WHERE type = 'share_revoked' AND (payload->>'linkId')::int = ?", [$link['id']]));

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/s/' . $token);
        self::assertResponseStatusCodeSame(410);
        $this->client->request('GET', '/s/' . $token . '/download');
        self::assertResponseStatusCodeSame(410);
        $this->client->request('GET', '/s/' . $token . '/view');
        self::assertResponseStatusCodeSame(410);
    }

    /** Адмін постачання не бачить приватної задачі — не відкликає її посилань і не бачить сторінки «Диск». */
    public function testSupplyAdminCannotTouchPrivateTaskLinks(): void
    {
        $author = $this->person(WorkRole::Manager, 'author');
        $supplyAdmin = $this->person(WorkRole::Production, 'sadmin', SupplyRole::Admin);
        $file = $this->taskFile($this->task($author, 'Приватна'), $author);

        $this->client->loginUser($author);
        $link = $this->post('/api/plan/files/task/' . $file->getId() . '/links', []);

        $this->client->loginUser($supplyAdmin);
        $this->post('/api/plan/share-links/' . $link['id'] . '/revoke', []);
        self::assertResponseStatusCodeSame(404);
        self::assertArrayNotHasKey('createdBy', $this->json());
        self::assertNull($this->db->fetchOne('SELECT revoked_at FROM share_link WHERE id = ?', [$link['id']]));
        $this->client->request('GET', '/api/plan/drive');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDangerousTypesNeverInline(): void
    {
        $author = $this->person(WorkRole::Manager, 'author');
        $task = $this->task($author, 'HTML');
        // Нові такі файли в задачу вже не приймаються (AttachmentStorage::assertAllowed), але
        // старі рядки, завантажені до allowlist, мають і далі віддаватися безпечно — імітуємо їх.
        $html = $this->legacy($this->taskFile($task, $author, 'x.txt', 'alert(1)'), 'x.html', 'text/html');
        $svg = $this->legacy($this->taskFile($task, $author, 'y.txt', 'svg'), 'y.svg', 'image/svg+xml');

        $this->client->loginUser($author);
        foreach ([$html, $svg] as $file) {
            $this->client->request('GET', '/api/plan/files/task/' . $file->getId() . '/inline');
            self::assertStringStartsWith('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
            self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        }

        $token = substr($this->post('/api/plan/files/task/' . $svg->getId() . '/links', [])['url'], -32);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/s/' . $token . '/view');
        self::assertResponseStatusCodeSame(410, 'SVG прев\'ю картинкою не віддається');
        $this->client->request('GET', '/s/' . $token . '/download');
        self::assertStringStartsWith('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    private function legacy(TaskAttachment $file, string $name, string $mime): TaskAttachment
    {
        $this->db->executeStatement('UPDATE task_attachment SET original_name = ?, mime = ? WHERE id = ?', [$name, $mime, $file->getId()]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($file);

        return $file;
    }

    public function testLinkDiesWhenAuthorLosesAccessOrFileDeleted(): void
    {
        $author = $this->person(WorkRole::Production, 'author');
        $owner = $this->person(WorkRole::Manager, 'owner');
        $task = $this->task($owner, 'Доступ', [$author]);
        $file = $this->taskFile($task, $author);

        $this->client->loginUser($author);
        $token = substr($this->post('/api/plan/files/task/' . $file->getId() . '/links', [])['url'], -32);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/s/' . $token);
        self::assertResponseIsSuccessful();

        // EM запиту скидається після кожного запиту — міняємо базу напряму.
        $this->db->executeStatement('UPDATE telegram_user SET archived_at = NOW() WHERE id = ?', [$author->getId()]);
        $this->client->request('GET', '/s/' . $token);
        self::assertResponseStatusCodeSame(410);

        $this->db->executeStatement('UPDATE telegram_user SET archived_at = NULL WHERE id = ?', [$author->getId()]);
        $this->client->request('GET', '/s/' . $token);
        self::assertResponseIsSuccessful();

        $this->db->executeStatement('UPDATE task_message SET deleted_at = NOW() WHERE id = ?', [$file->getMessage()->getId()]);
        $this->client->request('GET', '/s/' . $token . '/download');
        self::assertResponseStatusCodeSame(410);
    }

    public function testUnknownOrMalformedTokenIs404(): void
    {
        $this->client->request('GET', '/s/' . str_repeat('A', 32));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/s/short');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDriveStatusPageOnlyForAdmins(): void
    {
        $worker = $this->person(WorkRole::Manager, 'worker');
        $admin = $this->person(WorkRole::Director, 'super', superadmin: true);
        $file = $this->taskFile($this->task($worker, 'Диск'), $worker);
        $this->db->executeStatement("UPDATE task_attachment SET drive_state = 'failed', drive_attempts = 6, drive_error = 'boom' WHERE id = ?", [$file->getId()]);

        $this->client->loginUser($worker);
        $this->client->request('GET', '/api/plan/drive');
        self::assertResponseStatusCodeSame(404);
        $this->post('/api/plan/drive/retry', ['all' => true]);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($admin);
        $status = $this->get('/api/plan/drive?state=failed');
        self::assertGreaterThanOrEqual(1, $status['counts']['task']['failed']);
        self::assertContains($file->getId(), array_column(array_filter($status['items'], static fn (array $i) => $i['type'] === 'task'), 'id'));

        $this->post('/api/plan/drive/retry', ['type' => 'task', 'id' => $file->getId()]);
        self::assertSame(1, $this->json()['queued']);
        self::assertSame('pending', $this->db->fetchOne('SELECT drive_state FROM task_attachment WHERE id = ?', [$file->getId()]));
    }

    public function testGoogleErrorsClassified(): void
    {
        self::assertSame(DriveException::AUTH, GoogleDriveClient::classify(new GoogleException('{"error": "invalid_grant"}', 400))->kind);
        self::assertSame(DriveException::AUTH, GoogleDriveClient::classify(new GoogleException('unauthorized', 401))->kind);
        self::assertSame(DriveException::QUOTA, GoogleDriveClient::classify(new GoogleException('quota', 403, null, [['reason' => 'userRateLimitExceeded']]))->kind);
        self::assertSame(DriveException::QUOTA, GoogleDriveClient::classify(new GoogleException('slow down', 429))->kind);
        self::assertSame(DriveException::TRANSIENT, GoogleDriveClient::classify(new GoogleException('forbidden', 403, null, [['reason' => 'insufficientFilePermissions']]))->kind);
        self::assertSame(DriveException::TRANSIENT, GoogleDriveClient::classify(new GoogleException('backend', 500))->kind);
    }

    /** @return array<string, mixed> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $url, array $body): array
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
