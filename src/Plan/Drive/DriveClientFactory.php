<?php

namespace App\Plan\Drive;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Котрий Диск сьогодні: фейковий (PLAN_DRIVE_FAKE_DIR — локальний стенд),
 * бойовий (є GOOGLE_DRIVE_CLIENT_ID/SECRET/REFRESH_TOKEN) або жодного (null —
 * синхронізація чекає ключів, файли спокійно лежать у нашому сховищі).
 *
 * Копіює «План» лише в розкладці GOOGLE_DRIVE_LAYOUT=plan; на стендах заявок
 * (дефолт stand) дзеркало веде старий supply:drive-sync своїм деревом «Постачання/…».
 */
class DriveClientFactory
{
    public const string LAYOUT_PLAN = 'plan';

    private ?DriveClient $client = null;

    public function __construct(
        #[Autowire('%env(GOOGLE_DRIVE_CLIENT_ID)%')]
        private readonly string $clientId,
        #[Autowire('%env(GOOGLE_DRIVE_CLIENT_SECRET)%')]
        private readonly string $clientSecret,
        #[Autowire('%env(GOOGLE_DRIVE_REFRESH_TOKEN)%')]
        private readonly string $refreshToken,
        #[Autowire('%env(GOOGLE_DRIVE_ROOT_FOLDER_ID)%')]
        private readonly string $rootFolderId,
        #[Autowire('%env(default::GOOGLE_DRIVE_LAYOUT)%')]
        private readonly ?string $layout = null,
        #[Autowire('%env(default::PLAN_DRIVE_FAKE_DIR)%')]
        private readonly ?string $fakeDir = null,
    ) {
    }

    public function isPlanLayout(): bool
    {
        return $this->layout === self::LAYOUT_PLAN;
    }

    public function isFake(): bool
    {
        return (string) $this->fakeDir !== '';
    }

    public function isConfigured(): bool
    {
        return $this->isFake() || ($this->clientId !== '' && $this->clientSecret !== '' && $this->refreshToken !== '');
    }

    public function client(): ?DriveClient
    {
        if (! $this->isConfigured()) {
            return null;
        }

        return $this->client ??= $this->isFake()
            ? new FakeDriveClient((string) $this->fakeDir)
            : new GoogleDriveClient($this->clientId, $this->clientSecret, $this->refreshToken, $this->rootFolderId);
    }
}
