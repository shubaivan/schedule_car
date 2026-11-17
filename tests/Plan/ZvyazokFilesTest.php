<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Drive\DriveSync;
use App\Plan\Drive\FakeDriveClient;
use App\Plan\Share\FileType;
use App\Plan\Zvyazok\JpegOrientation;
use App\Plan\Zvyazok\ZvyazokFiles;
use App\Tasks\Enum\WorkRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

/**
 * «Звʼязок» — файли (Олександр 17.11): фото стискаються (≤1600 px, JPEG), документи й PDF
 * як є, до 20 МБ; віддаються лише учасникам; видалене повідомлення ховає файли; Диск —
 * «План/<рік>/Звʼязок/№<id> <назва>/<дата> <файл>»; новий учасник бачить усю історію.
 */
class ZvyazokFilesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;
    private string $tmp;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->db->beginTransaction();
        $this->tmp = sys_get_temp_dir() . '/zv-test-' . uniqid();
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        array_map('unlink', glob($this->tmp . '/*') ?: []);
        @rmdir($this->tmp);
        parent::tearDown();
    }

    public function testPhotoIsCompressedPdfAndOfficeKeptOnlyMembersDownload(): void
    {
        $ann = $this->user('Анна');
        $bob = $this->user('Борис');
        $outsider = $this->user('Сторонній');
        $this->client->loginUser($ann);
        $chat = $this->json('POST', '/api/plan/zvyazok/chats', ['kind' => 'group', 'title' => 'Цех / №2', 'memberIds' => [$bob->getId()]]);

        $photo = $this->photo(3200, 2000);
        $pdf = $this->pdf();
        $message = $this->upload($chat['id'], 'Фото з цеху й акт', [
            new UploadedFile($photo, 'IMG_0001.png', 'image/png', null, true),
            new UploadedFile($pdf, 'акт.pdf', 'application/pdf', null, true),
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertCount(2, $message['files']);
        [$img, $doc] = $message['files'];
        self::assertSame('image', $img['kind']);
        self::assertSame('IMG_0001.jpg', $img['name'], 'після стиснення — JPEG');
        self::assertSame('image/jpeg', $img['mime']);
        self::assertSame(1600, max($img['width'], $img['height']), 'довша сторона — 1600 px');
        self::assertLessThan($img['originalSize'], $img['size']);
        self::assertSame('pdf', $doc['kind']);
        self::assertNull($doc['thumbUrl']);

        // Учасник — бачить і скачує.
        $this->client->loginUser($bob);
        $feed = $this->json('GET', '/api/plan/zvyazok/chats/' . $chat['id'] . '/messages');
        self::assertCount(2, $feed['items'][0]['files']);
        $this->client->request('GET', $img['thumbUrl']);
        self::assertResponseIsSuccessful();
        self::assertSame('image/jpeg', $this->client->getResponse()->headers->get('Content-Type'));
        $this->client->request('GET', $doc['viewUrl']);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('inline', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->client->request('GET', $doc['downloadUrl']);
        self::assertStringStartsWith('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        // Не учасник — 404 на все.
        $this->client->loginUser($outsider);
        foreach ([$img['thumbUrl'], $img['viewUrl'], $doc['downloadUrl']] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }

        // Лише повідомлення з файлом — у списку чатів «📎 назва».
        $this->client->loginUser($ann);
        $this->upload($chat['id'], '', [new UploadedFile($this->pdf(), 'рахунок.pdf', 'application/pdf', null, true)]);
        self::assertResponseStatusCodeSame(201);
        $row = array_first(array_filter($this->json('GET', '/api/plan/zvyazok/chats')['items'], static fn ($r) => $r['id'] === $chat['id']));
        self::assertSame('📎 рахунок.pdf', $row['last']['text']);
        self::assertSame([$chat['id']], array_column($this->json('GET', '/api/plan/zvyazok/chats?q=' . urlencode('рахунок'))['items'], 'id'), 'пошук за назвою файла');

        // Видалене для всіх — файли зникають і не віддаються.
        $this->json('DELETE', '/api/plan/zvyazok/messages/' . $message['id']);
        self::assertResponseIsSuccessful();
        $this->client->loginUser($bob);
        $this->client->request('GET', $doc['downloadUrl']);
        self::assertResponseStatusCodeSame(404);
        $feed = $this->json('GET', '/api/plan/zvyazok/chats/' . $chat['id'] . '/messages');
        self::assertSame([], $feed['items'][0]['files']);
    }

    public function testRejectsWrongTypesTooManyAndNonAjaxForms(): void
    {
        $ann = $this->user('Анна');
        $bob = $this->user('Борис');
        $this->client->loginUser($ann);
        $chat = $this->json('POST', '/api/plan/zvyazok/chats', ['kind' => 'direct', 'userId' => $bob->getId()]);

        $html = $this->tmp . '/x.html';
        file_put_contents($html, '<html><script>alert(1)</script></html>');
        $out = $this->upload($chat['id'], 'гляньте', [new UploadedFile($html, 'звіт.pdf', 'application/pdf', null, true)]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('не приймаємо', $out['error']);

        $zip = $this->tmp . '/a.zip';
        $z = new ZipArchive();
        $z->open($zip, ZipArchive::CREATE);
        $z->addFromString('a.txt', 'a');
        $z->close();
        $this->upload($chat['id'], '', [new UploadedFile($zip, 'архів.zip', 'application/zip', null, true)]);
        self::assertResponseStatusCodeSame(422, 'архіви — ні: лише фото, PDF і офісні');

        $files = [];
        for ($i = 0; $i <= ZvyazokFiles::MAX_FILES; ++$i) {
            $files[] = new UploadedFile($this->pdf(), "f$i.pdf", 'application/pdf', null, true);
        }
        $this->upload($chat['id'], '', $files);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_chat_message WHERE chat_id = ?', [$chat['id']]), 'відмова — нічого не записано');

        // Форма без X-Requested-With (чужий сайт) — відмова.
        $this->client->request('POST', '/api/plan/zvyazok/chats/' . $chat['id'] . '/messages', ['text' => 'x'], ['files' => [new UploadedFile($this->pdf(), 'a.pdf', 'application/pdf', null, true)]]);
        self::assertResponseStatusCodeSame(415);
        // Порожнє повідомлення без файлів — ні.
        $this->upload($chat['id'], '  ', []);
        self::assertResponseStatusCodeSame(422);
    }

    public function testNewMemberSeesFullHistoryAndDriveFolder(): void
    {
        $ann = $this->user('Анна');
        $bob = $this->user('Борис');
        $late = $this->user('Віра');
        $this->client->loginUser($ann);
        $chat = $this->json('POST', '/api/plan/zvyazok/chats', ['kind' => 'group', 'title' => 'Обʼєкт «Лісовий»', 'memberIds' => [$bob->getId()]]);
        $first = $this->upload($chat['id'], 'Креслення', [new UploadedFile($this->pdf(), 'креслення.pdf', 'application/pdf', null, true)]);
        $this->json('PATCH', '/api/plan/zvyazok/chats/' . $chat['id'], ['add' => [$late->getId()]]);

        $this->client->loginUser($late);
        $feed = $this->json('GET', '/api/plan/zvyazok/chats/' . $chat['id'] . '/messages');
        self::assertSame([$first['id']], array_column($feed['items'], 'id'), 'новий учасник бачить усю історію');
        $this->client->request('GET', $first['files'][0]['downloadUrl']);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json('GET', '/api/plan/zvyazok/unread')['unread'], 'стара історія — не «непрочитане»');

        // Диск: План / <рік> / Звʼязок / №<id> <назва> / <дата> <файл>.
        $drive = new FakeDriveClient($this->tmp . '/drive');
        $result = self::getContainer()->get(DriveSync::class)->run($drive, 10, only: FileType::Zvyazok);
        self::assertSame(1, $result->sent);
        $year = date('Y');
        self::assertStringContainsString(sprintf('План/%s/Звʼязок/№%d Обʼєкт «Лісовий»/%s креслення.pdf', $year, $chat['id'], date('Y-m-d')), implode("\n", $result->lines));
        self::assertSame('done', $this->db->fetchOne('SELECT drive_state FROM plan_chat_attachment WHERE id = ?', [$first['files'][0]['id']]));
    }

    public function testLeftMemberCrossSiteFakeOfficeHugeImageAndNonAsciiName(): void
    {
        $ann = $this->user('Анна');
        $bob = $this->user('Борис');
        $this->client->loginUser($ann);
        $chat = $this->json('POST', '/api/plan/zvyazok/chats', ['kind' => 'group', 'title' => 'Склад', 'memberIds' => [$bob->getId()]]);

        // Не-ASCII розширення — скачування працює (раніше 500 через fallback-ім'я).
        $odd = $this->upload($chat['id'], '', [new UploadedFile($this->pdf(), 'звіт.пдф', 'application/pdf', null, true)]);
        self::assertResponseStatusCodeSame(201);
        $this->client->request('GET', $odd['files'][0]['downloadUrl']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('file-' . $odd['files'][0]['id'] . '.bin', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->client->request('GET', $odd['files'][0]['viewUrl']);
        self::assertStringContainsString('sandbox', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));

        // Будь-який zip під .docx і будь-що під .doc — ні.
        $zip = $this->tmp . '/fake.docx';
        $z = new ZipArchive();
        $z->open($zip, ZipArchive::CREATE);
        $z->addFromString('readme.txt', 'not a document');
        $z->close();
        $this->upload($chat['id'], '', [new UploadedFile($zip, 'звіт.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true)]);
        self::assertResponseStatusCodeSame(422, 'zip без word/ — не документ');
        $bin = $this->tmp . '/fake.doc';
        file_put_contents($bin, "MZ\x90\x00" . random_bytes(2000));
        $this->upload($chat['id'], '', [new UploadedFile($bin, 'акт.doc', 'application/msword', null, true)]);
        self::assertResponseStatusCodeSame(422, 'не OLE — не .doc');

        // Справжній docx — так.
        $this->upload($chat['id'], '', [new UploadedFile($this->docx(), 'специфікація.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true)]);
        self::assertResponseStatusCodeSame(201);

        // «Бомба»: мало байтів, забагато пікселів — відмова без розпакування.
        $huge = $this->tmp . '/huge.png';
        $img = imagecreate(8000, 7000);
        imagecolorallocate($img, 255, 255, 255);
        imagepng($img, $huge, 9);
        $out = $this->upload($chat['id'], '', [new UploadedFile($huge, 'скан.png', 'image/png', null, true)]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('завелике', $out['error']);

        // Запит з чужого сайту — навіть з X-Requested-With.
        $this->client->request('POST', '/api/plan/zvyazok/chats/' . $chat['id'] . '/messages', ['text' => 'x'], ['files' => [new UploadedFile($this->pdf(), 'a.pdf', 'application/pdf', null, true)]], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(415);

        // Вийшов з чату — файли більше не віддаються, писати не можна.
        $this->client->loginUser($bob);
        $this->json('POST', '/api/plan/zvyazok/chats/' . $chat['id'] . '/leave');
        $this->client->request('GET', $odd['files'][0]['downloadUrl']);
        self::assertResponseStatusCodeSame(404);
        $this->upload($chat['id'], 'я тут', [new UploadedFile($this->pdf(), 'b.pdf', 'application/pdf', null, true)]);
        self::assertResponseStatusCodeSame(404);

        // Сторінка «Диск» не розкриває назв і посилань файлів чатів.
        $admin = $this->user('Адмін');
        $admin->setWorkRole(WorkRole::Admin);
        $this->em->flush();
        $this->client->loginUser($admin);
        $status = $this->json('GET', '/api/plan/drive?state=pending');
        $zv = array_values(array_filter($status['items'] ?? [], static fn ($i) => $i['type'] === 'zv'));
        self::assertNotEmpty($zv);
        foreach ($zv as $i) {
            self::assertStringStartsWith('Файл «Звʼязку» #', $i['name']);
            self::assertNull($i['driveUrl']);
        }
    }

    public function testExifOrientationsOnRealImage(): void
    {
        // Полотно 3×2 з різними кольорами в кутах; після виправлення орієнтації 5..8 — 2×3.
        foreach ([5 => [0, 0], 6 => [1, 0], 7 => [1, 2], 8 => [0, 2]] as $orientation => [$x, $y]) {
            $img = imagecreatetruecolor(3, 2);
            imagesetpixel($img, 0, 0, 0xFF0000); // червоний — верхній лівий кут знімка
            $fixed = ZvyazokFiles::orient($img, $orientation);
            self::assertSame([2, 3], [imagesx($fixed), imagesy($fixed)], "орієнтація $orientation");
            self::assertSame(0xFF0000, imagecolorat($fixed, $x, $y) & 0xFFFFFF, "орієнтація $orientation: червоний кут на місці");
        }
    }

    public function testJpegOrientationParser(): void
    {
        // Мінімальний JPEG-заголовок з APP1/Exif, Orientation = 6 (big-endian «MM»).
        $tiff = 'MM' . pack('n', 42) . pack('N', 8) . pack('n', 1) . pack('n', 0x0112) . pack('n', 3) . pack('N', 1) . pack('n', 6) . "\0\0" . pack('N', 0);
        $app1 = "Exif\0\0" . $tiff;
        $jpeg = "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . "\xFF\xDA";
        self::assertSame(6, JpegOrientation::parse($jpeg));
        self::assertSame(1, JpegOrientation::parse("\xFF\xD8\xFF\xDA"));
        self::assertSame(1, JpegOrientation::parse('not a jpeg'));
    }

    /**
     * @param list<UploadedFile> $files
     *
     * @return array<string, mixed>
     */
    private function upload(int $chatId, string $text, array $files): array
    {
        $this->client->request('POST', '/api/plan/zvyazok/chats/' . $chatId . '/messages', ['text' => $text], ['files' => $files], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function json(string $method, string $url, array $body = []): array
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: $method === 'GET' ? null : json_encode($body, JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function photo(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 400; ++$i) {
            imagefilledellipse($img, random_int(0, $w), random_int(0, $h), random_int(20, 300), random_int(20, 300), (int) imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        $path = $this->tmp . '/p' . uniqid() . '.png';
        imagepng($img, $path);

        return $path;
    }

    private function docx(): string
    {
        $path = $this->tmp . '/d' . uniqid() . '.docx';
        $z = new ZipArchive();
        $z->open($path, ZipArchive::CREATE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $z->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');
        $z->close();

        return $path;
    }

    private function pdf(): string
    {
        $path = $this->tmp . '/d' . uniqid() . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return $path;
    }

    private function user(string $name): TelegramUser
    {
        $user = new TelegramUser()
            ->setTelegramId('zvf-' . uniqid('', true))
            ->setFirstName($name)
            ->setLastName('Тест')
            ->setWorkRole(WorkRole::Manager)
            ->setPlanFullAccess(true);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
