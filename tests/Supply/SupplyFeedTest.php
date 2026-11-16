<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Service\CrmLoginLink;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Dto\PurchaseInput;
use App\Supply\Entity\Supplier;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\AttachmentType;
use App\Supply\Enum\PaymentType;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\Unit;
use App\Supply\Service\AddComment;
use App\Supply\Service\AttachFile;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\RecordPurchase;
use App\Supply\Service\SupplyFeed;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Стрічка заявки у форматі задачі (SupplyFeed) — те, що малює «План» тими самими
 * бульбашками чату: зміст, порядок, права на запис і віддача файлів для перегляду.
 */
class SupplyFeedTest extends WebTestCase
{
    private const string MARKER = 'Тест-Стрічка';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(TelegramUser::class)->findBy(['first_name' => self::MARKER]) as $user) {
            foreach ($this->em->getRepository(SupplyRequest::class)->findBy(['author' => $user]) as $request) {
                $this->em->remove($request);
            }
        }
        $this->em->flush();
        foreach ($this->em->getRepository(Supplier::class)->findBy(['name' => self::MARKER . ' постачальник']) as $supplier) {
            $this->em->remove($supplier);
        }
        $this->em->flush();
        foreach ($this->em->getRepository(TelegramUser::class)->findBy(['first_name' => self::MARKER]) as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
        foreach ($this->tmp as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function testFeedMergesStatusCommentsPurchasesAndFilesInTaskFormat(): void
    {
        $worker = $this->user(SupplyRole::Worker);
        $manager = $this->user(SupplyRole::Manager);
        $request = $this->request($worker);
        self::getContainer()->get(AddComment::class)($request, $manager, 'Уточню ціну');
        $supplier = new Supplier()->setName(self::MARKER . ' постачальник');
        $this->em->persist($supplier);
        $this->em->flush();
        self::getContainer()->get(RecordPurchase::class)($request, $manager, new PurchaseInput(supplier: $supplier, totalAmount: '1200', quantity: null, pricePerUnit: null, payment: PaymentType::Bank, vatIncluded: true, invoiceNumber: 'Н-7', purchasedAt: null));
        $attachment = self::getContainer()->get(AttachFile::class)($request, $manager, "\xFF\xD8\xFFфото", 'фото.jpg', 'image/jpeg', AttachmentType::Photo, notify: false);

        $this->login($manager);
        $feed = $this->get('/api/supply/requests/' . $request->getId() . '/feed');

        self::assertSame('new', $feed['status']);
        self::assertSame(['comment' => true, 'attach' => true, 'removeFiles' => true], $feed['can']);
        self::assertSame([], $feed['reads'], 'формат як у задачі: поле є, хоч «хто прочитав» у заявки немає');

        $messages = $feed['messages'];
        self::assertCount(4, $messages);
        self::assertSame(['created', 'comment', 'updated', 'file'], array_column($messages, 'eventType'), 'подача → коментар → закупівля → документ, за часом');
        self::assertSame(['event', 'comment', 'event', 'comment'], array_column($messages, 'kind'));
        self::assertCount(4, array_unique(array_column($messages, 'id')), 'синтетичні id з різних таблиць не перетинаються');
        self::assertStringContainsString('заявку подано — Цемент М500', $messages[0]['text']);
        self::assertSame('Уточню ціну', $messages[1]['text']);
        self::assertTrue($messages[1]['mine']);
        self::assertStringContainsString(self::MARKER . ' постачальник', $messages[2]['text']);
        self::assertStringContainsString('накладна Н-7', $messages[2]['text']);

        // Документ — повідомлення з одним вкладенням у форматі вкладення задачі.
        $file = $messages[3]['attachments'][0];
        self::assertSame($attachment->getId(), $file['id']);
        self::assertSame(SupplyFeed::messageId((int) $attachment->getId(), SupplyFeed::SOURCE_FILE), $messages[3]['id']);
        self::assertSame($messages[3]['id'], $file['messageId']);
        self::assertSame('image', $file['kind']);
        self::assertSame('JPG', $file['ext']);
        self::assertSame('photo', $file['type']);
        self::assertStringEndsWith('/inline', (string) $file['urls']['view']);
        self::assertTrue($messages[3]['can']['delete'], 'менеджер може прибрати документ');
        self::assertFalse($messages[1]['can']['edit'], 'правок коментарів у заявок немає');
        self::assertSame([$file], $feed['files']);
        foreach (['author', 'mentions', 'replyTo', 'pinned', 'starred', 'editedAt', 'deleted'] as $key) {
            self::assertArrayHasKey($key, $messages[1], 'поле повідомлення задачі: ' . $key);
        }
    }

    public function testAuthorPostsCommentAndFileInOneSend(): void
    {
        $worker = $this->user(SupplyRole::Worker);
        $request = $this->request($worker);
        $this->login($worker);

        $this->client->request('POST', '/api/supply/requests/' . $request->getId() . '/feed', ['text' => 'Ось фото з об’єкта'], ['files' => [$this->upload('obj.jpg', "\xFF\xD8\xFFJPEG")]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $feed = json_decode((string) $this->client->getResponse()->getContent(), true);
        $types = array_column($feed['messages'], 'eventType');
        self::assertContains('comment', $types);
        self::assertContains('file', $types);
        self::assertSame('photo', $feed['files'][0]['type'], 'тип без вибору: фото — «Фото»');
        self::assertFalse($feed['can']['removeFiles'], 'автор не прибирає документи');
    }

    public function testStrangerCannotWriteAndNothingIsSavedHalfway(): void
    {
        $request = $this->request($this->user(SupplyRole::Worker));
        $this->login($this->user(SupplyRole::Worker));

        $this->client->request('POST', '/api/supply/requests/' . $request->getId() . '/feed', ['text' => 'чужий коментар'], ['files' => [$this->upload('x.jpg', "\xFF\xD8\xFFJPEG")]]);

        // «План»: чужа заявка для стороннього — як неіснуюча.
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM supply_comment WHERE request_id = ?', [$request->getId()]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM supply_attachment WHERE request_id = ?', [$request->getId()]));
    }

    /** Директор пише в стрічку (питання до оплати), але документи носять автор і менеджер. */
    public function testDirectorCommentsButCannotAttach(): void
    {
        $request = $this->request($this->user(SupplyRole::Worker));
        $this->login($this->user(SupplyRole::Director));
        $url = '/api/supply/requests/' . $request->getId() . '/feed';

        $this->client->request('POST', $url, ['text' => 'Чому так дорого?']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $feed = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($feed['can']['comment']);
        self::assertFalse($feed['can']['attach']);

        $this->client->request('POST', $url, ['text' => ''], ['files' => [$this->upload('d.jpg', "\xFF\xD8\xFFJPEG")]]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /** Невідповідний файл відмовляє ДО запису коментаря: повтор не дасть дубля. */
    public function testBadFileRefusesWholeSendBeforeComment(): void
    {
        $worker = $this->user(SupplyRole::Worker);
        $request = $this->request($worker);
        $this->login($worker);

        $this->client->request('POST', '/api/supply/requests/' . $request->getId() . '/feed', ['text' => 'Ось архів'], ['files' => [$this->upload('a.zip', "PK\x03\x04zip")]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM supply_comment WHERE request_id = ?', [$request->getId()]));
    }

    public function testEmptySendIsRefused(): void
    {
        $worker = $this->user(SupplyRole::Worker);
        $request = $this->request($worker);
        $this->login($worker);

        $this->client->request('POST', '/api/supply/requests/' . $request->getId() . '/feed', ['text' => '   ']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testInlineShowsPhotosAndPdfButDownloadsOffice(): void
    {
        $manager = $this->user(SupplyRole::Manager);
        $request = $this->request($this->user(SupplyRole::Worker));
        $attach = self::getContainer()->get(AttachFile::class);
        $photo = $attach($request, $manager, "\xFF\xD8\xFFфото", 'фото.jpg', 'image/jpeg', AttachmentType::Photo, notify: false);
        $doc = $attach($request, $manager, 'PK docx', 'рахунок.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', AttachmentType::Bill, notify: false);
        $other = $this->request($this->user(SupplyRole::Worker));
        $this->login($manager);

        $this->client->request('GET', sprintf('/api/supply/requests/%d/attachments/%d/inline', $request->getId(), $photo->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('inline', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));

        $this->client->request('GET', sprintf('/api/supply/requests/%d/attachments/%d/inline', $request->getId(), $doc->getId()));
        self::assertStringStartsWith('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'), 'Word браузер сам не покаже — скачуванням');

        // Файл чужої заявки за підміненим id не віддаємо.
        $this->client->request('GET', sprintf('/api/supply/requests/%d/attachments/%d/inline', $other->getId(), $photo->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testFeedIsClosedWithoutLogin(): void
    {
        $request = $this->request($this->user(SupplyRole::Worker));

        $this->client->request('GET', '/api/supply/requests/' . $request->getId() . '/feed');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'feed');
        file_put_contents($path, $contents);
        $this->tmp[] = $path;

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    /** @return array<string, mixed> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function login(TelegramUser $user): void
    {
        $url = self::getContainer()->get(CrmLoginLink::class)->issue($user);
        $this->client->request('GET', '/crm/auth/' . substr($url, strrpos($url, '/') + 1));
    }

    private function request(TelegramUser $author): SupplyRequest
    {
        return (self::getContainer()->get(CreateRequest::class))(
            $author,
            new CreateRequestInput(item: 'Цемент М500', quantity: '10', unit: Unit::Pack),
        );
    }

    private function user(SupplyRole $role): TelegramUser
    {
        $user = new TelegramUser()
            ->setTelegramId('feed-' . uniqid())
            ->setFirstName(self::MARKER)
            ->setSupplyRole($role);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
