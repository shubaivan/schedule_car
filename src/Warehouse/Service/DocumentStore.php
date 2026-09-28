<?php

namespace App\Warehouse\Service;

use App\Entity\TelegramUser;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhDocument;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\DocumentType;
use App\Warehouse\Exception\WarehouseException;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Документи складу: прийняти, віддати, прибрати.
 *
 * Ті самі правила, що й у заявках (AttachFile): файл лягає поза public/ під
 * згенерованим ім'ям, копія на Google Диск їде окремим кроком за кроном.
 */
class DocumentStore
{
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
        private FilesystemOperator $warehouseStorage,
        private LoggerInterface $logger,
    ) {
    }

    public function attach(
        WhClient|WhSite|WhItem|WhMovement $owner,
        TelegramUser $by,
        string $contents,
        string $originalName,
        string $mime,
        DocumentType $type = DocumentType::Other,
    ): WhDocument {
        if (! WarehouseSection::canManage($by)) {
            throw new WarehouseException('Документи складу додають менеджер, адміністратор або директор.');
        }

        $size = strlen($contents);

        if ($size === 0) {
            throw new WarehouseException('Файл порожній.');
        }

        if ($size > self::MAX_SIZE) {
            throw new WarehouseException(sprintf('Файл завеликий: максимум %d МБ.', self::MAX_SIZE / 1024 / 1024));
        }

        $mime = strtolower(trim($mime));

        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw new WarehouseException('Приймаємо PDF, фото та документи Word/Excel.');
        }

        $originalName = $this->safeName($originalName);
        $path = $this->path($owner, $originalName);

        $this->warehouseStorage->write($path, $contents);

        $document = (new WhDocument())
            ->attachTo($owner)
            ->setType($type)
            ->setOriginalName($originalName)
            ->setStoragePath($path)
            ->setMime($mime)
            ->setSize($size)
            ->setSha256(hash('sha256', $contents))
            ->setUploadedBy($by);

        $this->em->persist($document);
        $this->em->flush();

        $this->logger->info('warehouse: додано документ', [
            'document' => $document->getId(),
            'name' => $originalName,
            'size' => $size,
            'by' => $by->displayName(),
        ]);

        return $document;
    }

    public function read(WhDocument $document): string
    {
        return $this->warehouseStorage->read($document->getStoragePath());
    }

    public function remove(WhDocument $document, TelegramUser $by): void
    {
        if (! WarehouseSection::canManage($by)) {
            throw new WarehouseException('Видаляти документи складу можуть менеджер, адміністратор або директор.');
        }

        try {
            $this->warehouseStorage->delete($document->getStoragePath());
        } catch (Throwable $e) {
            $this->logger->warning('warehouse: не вдалось видалити файл зі сховища', [
                'path' => $document->getStoragePath(),
                'error' => $e->getMessage(),
            ]);
        }

        // Копію на Google Диску навмисно лишаємо: там архів, з якого нічого не зникає само.
        $this->em->remove($document);
        $this->em->flush();
    }

    /** @return WhDocument[] */
    public function of(WhClient|WhSite|WhItem|WhMovement $owner): array
    {
        $field = match (true) {
            $owner instanceof WhClient => 'client',
            $owner instanceof WhSite => 'site',
            $owner instanceof WhItem => 'item',
            default => 'movement',
        };

        return $this->em->getRepository(WhDocument::class)->findBy([$field => $owner], ['id' => 'DESC']);
    }

    /** clients/12/20260928-121500-a1b2c3d4e5f6a7b8.pdf */
    private function path(WhClient|WhSite|WhItem|WhMovement $owner, string $originalName): string
    {
        $folder = match (true) {
            $owner instanceof WhClient => 'clients',
            $owner instanceof WhSite => 'sites',
            $owner instanceof WhItem => 'items',
            default => 'movements',
        };

        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        return sprintf(
            '%s/%d/%s-%s%s',
            $folder,
            $owner->getId(),
            (new DateTime('now', new DateTimeZone('Europe/Kyiv')))->format('Ymd-His'),
            bin2hex(random_bytes(8)),
            $extension !== '' ? '.' . $extension : '',
        );
    }

    private function safeName(string $name): string
    {
        $name = trim(str_replace(['/', '\\', "\0", "\n", "\r"], ' ', $name));
        $name = (string) preg_replace('/\s+/u', ' ', $name);

        return mb_substr($name === '' ? 'файл' : $name, 0, 255);
    }
}
