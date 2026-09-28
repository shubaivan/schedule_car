<?php

namespace App\Warehouse\Service;

use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhDocument;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use Doctrine\ORM\EntityManagerInterface;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Копія документів складу на Google Диск — ті самі ключі, що й у заявок.
 *
 * Дерево тек — від клієнта, бо саме так документи шукають люди:
 *
 *     Склад / Клієнти / ТОВ Будінвест / Договір — Договір оренди.pdf
 *     Склад / Клієнти / ТОВ Будінвест / ЖК Сонячний / Накладна — №15.pdf
 *     Склад / Власні об'єкти / ЖК на Смілянській / …
 *     Склад / Надходження / 2026-09 / Накладна — ….pdf
 *     Склад / Позиції / ОП-0001 Щит 1200×600 / Паспорт.pdf
 *
 * Документ руху лягає в теку об'єкта, якого рух стосується; надходження й рухи
 * між складами — у «Надходження» за місяцем.
 */
class WarehouseDriveMirror
{
    private const ROOT_FOLDER = 'Склад';
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';

    private ?Drive $drive = null;

    /** @var array<string, string> */
    private array $folders = [];

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $clientId,
        private string $clientSecret,
        private string $refreshToken,
        private string $rootFolderId,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->refreshToken !== '';
    }

    public function mirror(WhDocument $document, string $contents): bool
    {
        if (! $this->isEnabled() || $document->isMirrored()) {
            return false;
        }

        try {
            $folderId = $this->folder(self::folderPath($document));

            $file = $this->drive()->files->create(
                new DriveFile(['name' => $document->getDriveName(), 'parents' => [$folderId]]),
                [
                    'data' => $contents,
                    'mimeType' => $document->getMime(),
                    'uploadType' => 'multipart',
                    'fields' => 'id,webViewLink',
                ],
            );

            $document->markMirrored((string) $file->getId(), $file->getWebViewLink());
            $this->em->flush();

            return true;
        } catch (Throwable $e) {
            $this->logger->error('warehouse: не вдалось скопіювати документ на Google Диск', [
                'document' => $document->getId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Шлях тек для документа — окремо від Google, щоб його можна було перевірити тестом.
     *
     * @return list<string>
     */
    public static function folderPath(WhDocument $document): array
    {
        $owner = $document->getOwner();

        $path = match (true) {
            $owner instanceof WhClient => self::clientPath($owner),
            $owner instanceof WhSite => self::sitePath($owner),
            $owner instanceof WhItem => ['Позиції', sprintf('%s %s', $owner->getInventoryNumber(), $owner->getName())],
            $owner instanceof WhMovement => $owner->getSite() !== null
                ? self::sitePath($owner->getSite())
                : ['Надходження', $owner->getOccurredAt()->format('Y-m')],
            default => ['Інше'],
        };

        return array_map(self::safe(...), [self::ROOT_FOLDER, ...$path]);
    }

    /** @return list<string> */
    private static function clientPath(WhClient $client): array
    {
        return ['Клієнти', $client->getName()];
    }

    /** @return list<string> */
    private static function sitePath(WhSite $site): array
    {
        if ($site->isWarehouse()) {
            return ['Склади', $site->getName()];
        }

        return $site->getClient() !== null
            ? [...self::clientPath($site->getClient()), $site->getName()]
            : ["Власні об'єкти", $site->getName()];
    }

    /** @param list<string> $path */
    private function folder(array $path): string
    {
        $parent = $this->rootFolderId !== '' ? $this->rootFolderId : 'root';

        foreach ($path as $name) {
            $parent = $this->ensureFolder($name, $parent);
        }

        return $parent;
    }

    private function ensureFolder(string $name, string $parentId): string
    {
        $key = $parentId . '/' . $name;

        if (isset($this->folders[$key])) {
            return $this->folders[$key];
        }

        $existing = $this->drive()->files->listFiles([
            'q' => sprintf(
                "mimeType = '%s' and name = '%s' and '%s' in parents and trashed = false",
                self::FOLDER_MIME,
                str_replace(['\\', "'"], ['\\\\', "\\'"], $name),
                $parentId,
            ),
            'fields' => 'files(id)',
            'pageSize' => 1,
        ])->getFiles();

        if ($existing) {
            return $this->folders[$key] = (string) $existing[0]->getId();
        }

        $folder = $this->drive()->files->create(
            new DriveFile(['name' => $name, 'mimeType' => self::FOLDER_MIME, 'parents' => [$parentId]]),
            ['fields' => 'id'],
        );

        return $this->folders[$key] = (string) $folder->getId();
    }

    private static function safe(string $name): string
    {
        $name = str_replace(['/', '\\'], '-', trim($name));

        return mb_substr((string) preg_replace('/\s+/u', ' ', $name), 0, 120);
    }

    private function drive(): Drive
    {
        if ($this->drive !== null) {
            return $this->drive;
        }

        $client = new Client();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setScopes([Drive::DRIVE_FILE]);
        $client->refreshToken($this->refreshToken);

        return $this->drive = new Drive($client);
    }
}
