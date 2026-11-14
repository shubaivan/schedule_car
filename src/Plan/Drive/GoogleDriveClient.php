<?php

namespace App\Plan\Drive;

use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Exception as GoogleException;
use Throwable;

/**
 * Бойовий клієнт: OAuth refresh-token акаунта заводу, scope лише drive.file
 * (бачимо тільки створене цим застосунком). Ключі — ті самі GOOGLE_DRIVE_*,
 * що в стенда заявок; у код, git і логи вони не потрапляють.
 *
 * Створюється фабрикою (DriveClientFactory) один раз на запуск: кеш тек живе
 * в об'єкті, тож дерево не перепитується для кожного файлу.
 */
final class GoogleDriveClient implements DriveClient
{
    private const string FOLDER_MIME = 'application/vnd.google-apps.folder';
    private const int MULTIPART_MAX = 5 * 1024 * 1024;
    /** Шматок resumable-завантаження — кратний 256 КБ, як вимагає Google. */
    private const int CHUNK = 4 * 1024 * 1024;

    /** Причини 403, які означають «зачекай», а не «файл поганий». */
    private const array QUOTA_REASONS = ['storageQuotaExceeded', 'quotaExceeded', 'userRateLimitExceeded', 'rateLimitExceeded', 'dailyLimitExceeded'];

    private ?Drive $drive = null;

    /** @var array<string, string> */
    private array $folders = [];

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
        private readonly string $rootFolderId = '',
    ) {
    }

    public function rootId(): string
    {
        return $this->rootFolderId !== '' ? $this->rootFolderId : 'root';
    }

    public function folder(string $name, string $parentId): string
    {
        $key = $parentId . '/' . $name;

        if (isset($this->folders[$key])) {
            return $this->folders[$key];
        }

        return $this->folders[$key] = $this->call(function () use ($name, $parentId): string {
            $existing = $this->drive()->files->listFiles([
                'q' => sprintf("mimeType = '%s' and name = '%s' and '%s' in parents and trashed = false", self::FOLDER_MIME, self::quote($name), self::quote($parentId)),
                'fields' => 'files(id)',
                'pageSize' => 1,
            ])->getFiles();

            if ($existing) {
                return (string) $existing[0]->getId();
            }

            return (string) $this->drive()->files->create(
                new DriveFile(['name' => $name, 'mimeType' => self::FOLDER_MIME, 'parents' => [$parentId]]),
                ['fields' => 'id'],
            )->getId();
        });
    }

    public function findByKey(string $planKey): ?DriveUpload
    {
        return $this->call(function () use ($planKey): ?DriveUpload {
            $found = $this->drive()->files->listFiles([
                'q' => sprintf("appProperties has { key='planKey' and value='%s' } and trashed = false", self::quote($planKey)),
                'fields' => 'files(id,webViewLink)',
                'pageSize' => 1,
            ])->getFiles();

            return $found ? new DriveUpload((string) $found[0]->getId(), $found[0]->getWebViewLink()) : null;
        });
    }

    public function nameTaken(string $name, string $parentId): bool
    {
        return $this->call(fn (): bool => (bool) $this->drive()->files->listFiles([
            'q' => sprintf("name = '%s' and '%s' in parents and trashed = false", self::quote($name), self::quote($parentId)),
            'fields' => 'files(id)',
            'pageSize' => 1,
        ])->getFiles());
    }

    public function upload(string $name, string $mime, string $contents, string $parentId, string $planKey): DriveUpload
    {
        return $this->call(function () use ($name, $mime, $contents, $parentId, $planKey): DriveUpload {
            $meta = new DriveFile(['name' => $name, 'parents' => [$parentId], 'appProperties' => ['planKey' => $planKey]]);

            // Google радить multipart лише до 5 МБ; більше — resumable шматками, щоб 20 МБ не впирались у таймаут.
            if (strlen($contents) <= self::MULTIPART_MAX) {
                $file = $this->drive()->files->create($meta, ['data' => $contents, 'mimeType' => $mime, 'uploadType' => 'multipart', 'fields' => 'id,webViewLink']);

                return new DriveUpload((string) $file->getId(), $file->getWebViewLink());
            }

            $drive = $this->drive();
            $client = $drive->getClient();
            $client->setDefer(true);

            try {
                /** @var \Psr\Http\Message\RequestInterface $request */
                $request = $drive->files->create($meta, ['fields' => 'id,webViewLink']);
                $media = new MediaFileUpload($client, $request, $mime, '', true, self::CHUNK);
                $media->setFileSize(strlen($contents));
                $file = false;

                foreach (str_split($contents, self::CHUNK) as $chunk) {
                    $file = $media->nextChunk($chunk);
                }
            } finally {
                $client->setDefer(false);
            }

            if (! $file instanceof DriveFile) {
                throw DriveException::transient('Google не підтвердив завантаження файлу.');
            }

            return new DriveUpload((string) $file->getId(), $file->getWebViewLink());
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function call(callable $work): mixed
    {
        try {
            return $work();
        } catch (DriveException $e) {
            throw $e;
        } catch (GoogleException $e) {
            throw self::classify($e);
        } catch (Throwable $e) {
            throw DriveException::transient(self::short($e->getMessage()), $e);
        }
    }

    public static function classify(GoogleException $e): DriveException
    {
        $reasons = array_column($e->getErrors() ?? [], 'reason');
        $message = self::short($e->getMessage());

        if ($e->getCode() === 401 || str_contains($e->getMessage(), 'invalid_grant')) {
            return DriveException::auth($message, $e);
        }

        if ($e->getCode() === 429 || ($e->getCode() === 403 && array_intersect($reasons, self::QUOTA_REASONS) !== [])) {
            return DriveException::quota($message, $e);
        }

        return DriveException::transient($message, $e);
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

        $token = $client->fetchAccessTokenWithRefreshToken($this->refreshToken);

        if (isset($token['error'])) {
            // Сам токен у повідомлення не потрапляє — лише код помилки Google.
            $error = (string) $token['error'];

            throw $error === 'invalid_grant' || $error === 'unauthorized_client'
                ? DriveException::auth('Google відхилив refresh-token: ' . $error)
                : DriveException::transient('Не вдалось отримати доступ до Google Drive: ' . $error);
        }

        return $this->drive = new Drive($client);
    }

    private static function quote(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private static function short(string $message): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $message)), 0, 300);
    }
}
