<?php

namespace App\Supply\Service;

use App\Supply\Entity\SupplyAttachment;
use App\Supply\Entity\SupplyRequest;
use Doctrine\ORM\EntityManagerInterface;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Psr\Log\LoggerInterface;
use Throwable;

use function strlen;

/**
 * Копія документів у Google Drive — упорядкований архів для людей.
 *
 * Дерево папок: рік → місяць → заявник → заявка.
 *
 *     Постачання / 2026 / 2026-08 / Іван Левін / №042-2026 Цемент М400 / Накладна 042-2026.pdf
 *
 * Односторонньо: ми лише кладемо файли. Читати те, що люди залили в Drive
 * руками, навмисно НЕ вміємо — для цього Google вимагає широкі права
 * (restricted scope) і платний аудит, а користі рівно нуль: джерело правди
 * все одно наша база. Нам вистачає drive.file — доступу лише до того, що
 * створив сам застосунок.
 *
 * Вимкнено, поки в оточенні немає ключів: тоді sync просто нічого не робить,
 * а завантаження файлів у CRM працює як раніше.
 */
class GoogleDriveMirror
{
    private const ROOT_FOLDER = 'Постачання';
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';

    private ?Drive $drive = null;

    /** Кеш «шлях → id теки» на час одного запуску: тек мало, запитів багато. */
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

    /**
     * Відправити файл у Drive і запам'ятати посилання.
     *
     * Помилка не валить нічого: файл лишається в нашому сховищі й поїде
     * наступним запуском.
     */
    public function mirror(SupplyAttachment $attachment, string $contents): bool
    {
        if (! $this->isEnabled() || $attachment->isMirrored()) {
            return false;
        }

        try {
            $folderId = $this->folderFor($attachment->getRequest());

            $file = $this->drive()->files->create(
                new DriveFile([
                    'name' => $this->uniqueName($attachment, $folderId),
                    'parents' => [$folderId],
                ]),
                [
                    'data' => $contents,
                    'mimeType' => $attachment->getMime(),
                    'uploadType' => 'multipart',
                    'fields' => 'id,webViewLink',
                ],
            );

            $attachment
                ->setDriveFileId($file->getId())
                ->setDriveUrl($file->getWebViewLink());

            $this->em->flush();

            $this->logger->info('supply: файл скопійовано в Google Drive', [
                'attachment' => $attachment->getId(),
                'number' => $attachment->getRequest()->getNumber(),
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('supply: не вдалось скопіювати файл у Google Drive', [
                'attachment' => $attachment->getId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Рік → місяць → заявник → заявка. */
    private function folderFor(SupplyRequest $request): string
    {
        $created = $request->getCreatedAt();

        $path = [
            self::ROOT_FOLDER,
            $created->format('Y'),
            $created->format('Y-m'),
            $this->folderSafe($request->getAuthor()->displayName()),
            $this->folderSafe(sprintf(
                '№%s %s',
                str_replace('/', '-', $request->getNumber()),
                $request->getItem(),
            )),
        ];

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
                $this->escapeQuery($name),
                $parentId,
            ),
            'fields' => 'files(id)',
            'pageSize' => 1,
        ])->getFiles();

        if ($existing) {
            return $this->folders[$key] = $existing[0]->getId();
        }

        $folder = $this->drive()->files->create(
            new DriveFile([
                'name' => $name,
                'mimeType' => self::FOLDER_MIME,
                'parents' => [$parentId],
            ]),
            ['fields' => 'id'],
        );

        return $this->folders[$key] = $folder->getId();
    }

    /**
     * Дві накладні до однієї заявки не мають зливатись в одну назву:
     * Drive дозволяє однакові імена, а бухгалтер потім не розбереться.
     */
    private function uniqueName(SupplyAttachment $attachment, string $folderId): string
    {
        $name = $attachment->getDriveName();

        $taken = $this->drive()->files->listFiles([
            'q' => sprintf(
                "name = '%s' and '%s' in parents and trashed = false",
                $this->escapeQuery($name),
                $folderId,
            ),
            'fields' => 'files(id)',
            'pageSize' => 1,
        ])->getFiles();

        if (! $taken) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension !== '' ? substr($name, 0, -strlen($extension) - 1) : $name;

        return sprintf('%s (%d)%s', $base, $attachment->getId(), $extension !== '' ? '.' . $extension : '');
    }

    /** У теку Drive не можна класти скісні риски, а лапки ламають запит. */
    private function folderSafe(string $name): string
    {
        $name = str_replace(['/', '\\'], '-', trim($name));

        return mb_substr((string) preg_replace('/\s+/u', ' ', $name), 0, 120);
    }

    private function escapeQuery(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function drive(): Drive
    {
        if ($this->drive !== null) {
            return $this->drive;
        }

        $client = new Client();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        // drive.file — доступ лише до файлів, створених цим застосунком.
        $client->setScopes([Drive::DRIVE_FILE]);
        $client->refreshToken($this->refreshToken);

        return $this->drive = new Drive($client);
    }
}
