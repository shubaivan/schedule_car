<?php

namespace App\Supply\Service;

use App\Entity\TelegramUser;
use App\Supply\Entity\SupplyAttachment;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\AttachmentType;
use App\Supply\Exception\SupplyException;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Прийом файлу до заявки: перевірка, збереження у сховище, запис у базу.
 *
 * Файл лягає поза public/ під згенерованим ім'ям — оригінальне в шлях не
 * пускаємо (там буває і «../», і кирилиця, і однакові назви від різних людей).
 * Копію в Google Drive робить окремий крок: проблеми з Google не мають
 * ламати завантаження в CRM.
 */
class AttachFile
{
    /** 20 МБ — стеля Telegram на завантаження файлу ботом; тримаємо єдину для всіх каналів. */
    public const MAX_SIZE = 20 * 1024 * 1024;

    private const ALLOWED_MIME = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/heic',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private FilesystemOperator $supplyStorage,
        private SupplyNotifier $notifier,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        SupplyRequest $request,
        TelegramUser $by,
        string $contents,
        string $originalName,
        string $mime,
        AttachmentType $type = AttachmentType::Other,
        bool $notify = true,
    ): SupplyAttachment {
        if (! $by->getSupplyRole()->canManage() && $request->getAuthor()->getId() !== $by->getId()) {
            throw new SupplyException('Прикріпити файл можуть автор заявки й менеджер із постачання.');
        }

        $size = strlen($contents);

        if ($size === 0) {
            throw new SupplyException('Файл порожній.');
        }

        if ($size > self::MAX_SIZE) {
            throw new SupplyException(sprintf('Файл завеликий: максимум %d МБ.', self::MAX_SIZE / 1024 / 1024));
        }

        $mime = strtolower(trim($mime));

        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw new SupplyException('Приймаємо PDF, фото та документи Word/Excel.');
        }

        $originalName = $this->safeName($originalName);
        $path = $this->path($request, $originalName);

        $this->supplyStorage->write($path, $contents);

        $attachment = (new SupplyAttachment())
            ->setType($type)
            ->setOriginalName($originalName)
            ->setStoragePath($path)
            ->setMime($mime)
            ->setSize($size)
            ->setSha256(hash('sha256', $contents))
            ->setUploadedBy($by);

        $request->addAttachment($attachment);

        $this->em->persist($attachment);
        $this->em->flush();

        $this->logger->info('supply: прикріплено файл', [
            'number' => $request->getNumber(),
            'name' => $originalName,
            'size' => $size,
            'by' => $by->displayName(),
        ]);

        if ($notify) {
            $this->notifier->fileAttached($attachment);
        }

        return $attachment;
    }

    public function remove(SupplyAttachment $attachment, TelegramUser $by): void
    {
        if (! $by->getSupplyRole()->canManage()) {
            throw new SupplyException('Видаляти файли може лише менеджер із постачання.');
        }

        $request = $attachment->getRequest();
        $name = $attachment->getOriginalName();

        try {
            $this->supplyStorage->delete($attachment->getStoragePath());
        } catch (Throwable $e) {
            // Файла могло вже не бути — запис у базі все одно прибираємо.
            $this->logger->warning('supply: не вдалось видалити файл зі сховища', [
                'path' => $attachment->getStoragePath(),
                'error' => $e->getMessage(),
            ]);
        }

        $request->removeAttachment($attachment);
        $this->em->remove($attachment);
        $this->em->flush();

        // Копію в Google Drive навмисно не чіпаємо: там архів, з якого нічого
        // не зникає само по собі.
        $this->notifier->fileRemoved($request, $name);
    }

    public function read(SupplyAttachment $attachment): string
    {
        return $this->supplyStorage->read($attachment->getStoragePath());
    }

    /** 2026/042-2026/20260812-171233-a1b2c3d4e5f6a7b8.pdf */
    private function path(SupplyRequest $request, string $originalName): string
    {
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        return sprintf(
            '%s/%s/%s-%s%s',
            $request->getCreatedAt()->format('Y'),
            str_replace('/', '-', $request->getNumber()),
            (new DateTime('now', new DateTimeZone('Europe/Kyiv')))->format('Ymd-His'),
            bin2hex(random_bytes(8)),
            $extension !== '' ? '.' . $extension : '',
        );
    }

    /** Лишаємо людині зрозумілу назву, але без шляхів і керуючих символів. */
    private function safeName(string $name): string
    {
        $name = trim(str_replace(['/', '\\', "\0", "\n", "\r"], ' ', $name));
        $name = (string) preg_replace('/\s+/u', ' ', $name);

        return mb_substr($name === '' ? 'файл' : $name, 0, 255);
    }
}
