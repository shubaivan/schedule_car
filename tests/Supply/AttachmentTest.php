<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\AttachmentType;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\Unit;
use App\Supply\Exception\SupplyException;
use App\Supply\Service\AttachFile;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\GoogleDriveMirror;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Документи заявки: прийом, перевірки, віддача, видалення. */
class AttachmentTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AttachFile $attachFile;
    private FilesystemOperator $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->attachFile = self::getContainer()->get(AttachFile::class);
        $this->storage = self::getContainer()->get('supply.storage');
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testManagerAttachesInvoice(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        $attachment = ($this->attachFile)(
            $request,
            $manager,
            '%PDF-1.4 накладна',
            'Накладна №114.pdf',
            'application/pdf',
            AttachmentType::Invoice,
        );

        self::assertNotNull($attachment->getId());
        self::assertSame(AttachmentType::Invoice, $attachment->getType());
        self::assertSame(hash('sha256', '%PDF-1.4 накладна'), $attachment->getSha256());
        self::assertTrue($this->storage->fileExists($attachment->getStoragePath()), 'файл справді лежить у сховищі');
        self::assertSame('%PDF-1.4 накладна', $this->attachFile->read($attachment));
        self::assertCount(1, $request->getAttachments());
    }

    /**
     * Оригінальне ім'я не має потрапляти у шлях: там буває і «../», і однакові
     * назви від різних людей.
     */
    public function testOriginalNameNeverLeaksIntoPath(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        $attachment = ($this->attachFile)(
            $request,
            $manager,
            'зміст',
            '../../../etc/passwd.pdf',
            'application/pdf',
        );

        self::assertStringNotContainsString('..', $attachment->getStoragePath());
        self::assertStringNotContainsString('passwd', $attachment->getStoragePath());
        self::assertStringContainsString(
            str_replace('/', '-', $request->getNumber()),
            $attachment->getStoragePath(),
            'шлях будується з номера заявки',
        );
    }

    public function testExecutableIsRefused(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('PDF, фото та документи');

        ($this->attachFile)($request, $manager, 'MZ...', 'virus.exe', 'application/x-msdownload');
    }

    public function testTooBigFileIsRefused(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('завеликий');

        ($this->attachFile)(
            $request,
            $manager,
            str_repeat('x', AttachFile::MAX_SIZE + 1),
            'скан.pdf',
            'application/pdf',
        );
    }

    public function testEmptyFileIsRefused(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        $this->expectException(SupplyException::class);

        ($this->attachFile)($request, $manager, '', 'порожній.pdf', 'application/pdf');
    }

    /** Автор може прикріпити фото до своєї заявки, чужу — ні. */
    public function testStrangerCannotAttach(): void
    {
        [$worker] = $this->users();
        $request = $this->request($worker);
        $stranger = $this->user('stranger', SupplyRole::Worker);

        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('автор заявки й менеджер');

        ($this->attachFile)($request, $stranger, 'фото', 'photo.jpg', 'image/jpeg');
    }

    public function testAuthorCanAttachToOwnRequest(): void
    {
        [$worker] = $this->users();
        $request = $this->request($worker);

        $attachment = ($this->attachFile)($request, $worker, 'фото', 'photo.jpg', 'image/jpeg', AttachmentType::Photo);

        self::assertNotNull($attachment->getId());
    }

    public function testWorkerCannotDeleteFiles(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);
        $attachment = ($this->attachFile)($request, $manager, 'зміст', 'скан.pdf', 'application/pdf');

        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('лише менеджер');

        $this->attachFile->remove($attachment, $worker);
    }

    public function testRemoveDropsFileAndRecord(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);
        $attachment = ($this->attachFile)($request, $manager, 'зміст', 'скан.pdf', 'application/pdf');
        $path = $attachment->getStoragePath();

        $this->attachFile->remove($attachment, $manager);

        self::assertFalse($this->storage->fileExists($path));
        self::assertCount(0, $request->getAttachments());
    }

    /** Ім'я в Drive має пояснювати вміст без відкривання файлу. */
    public function testDriveNameSaysWhatItIs(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        $attachment = ($this->attachFile)(
            $request,
            $manager,
            'зміст',
            'scan_0001.PDF',
            'application/pdf',
            AttachmentType::Invoice,
        );

        self::assertSame(
            'Накладна ' . str_replace('/', '-', $request->getNumber()) . '.pdf',
            $attachment->getDriveName(),
        );
    }

    /** Без ключів у оточенні дзеркало мовчить, а не падає. */
    public function testDriveMirrorIsDisabledWithoutCredentials(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);
        $attachment = ($this->attachFile)($request, $manager, 'зміст', 'скан.pdf', 'application/pdf');

        $mirror = self::getContainer()->get(GoogleDriveMirror::class);

        self::assertFalse($mirror->isEnabled());
        self::assertFalse($mirror->mirror($attachment, 'зміст'));
        self::assertNull($attachment->getDriveUrl());
    }

    public function testSizeLabelIsHumanReadable(): void
    {
        [$worker, $manager] = $this->users();
        $request = $this->request($worker);

        $attachment = ($this->attachFile)(
            $request,
            $manager,
            str_repeat('x', 2_516_582),
            'скан.pdf',
            'application/pdf',
        );

        self::assertSame('2,4 МБ', $attachment->getSizeLabel());
    }

    private function request(TelegramUser $author): SupplyRequest
    {
        return (self::getContainer()->get(CreateRequest::class))(
            $author,
            new CreateRequestInput(item: 'Цемент М400', quantity: '40', unit: Unit::Piece),
        );
    }

    /** @return TelegramUser[] */
    private function users(): array
    {
        return [$this->user('worker', SupplyRole::Worker), $this->user('manager', SupplyRole::Manager)];
    }

    private function user(string $prefix, SupplyRole $role): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId($prefix . '-file-' . uniqid())
            ->setFirstName('Тест-Файл')
            ->setSupplyRole($role);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
