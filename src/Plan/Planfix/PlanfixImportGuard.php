<?php

namespace App\Plan\Planfix;

use Doctrine\DBAL\Connection;

/**
 * Запобіжник проду для всього, що пише в задачі Плану (--apply, відкат, relink):
 *
 * - змінна PLANFIX_IMPORT_ALLOW=1 саме в середовищі процесу — читаємо лише getenv(),
 *   не $_SERVER/$_ENV, куди Dotenv підкладає значення з .env*-файлів;
 * - --confirm=<назва бази> у команді — щоб випадково не влучити не в ту базу.
 */
final class PlanfixImportGuard
{
    public const string ENV = 'PLANFIX_IMPORT_ALLOW';

    public static function allowed(): bool
    {
        return getenv(self::ENV) === '1';
    }

    /** @return string|null текст помилки або null, якщо можна */
    public static function check(Connection $db, ?string $confirm): ?string
    {
        if (! self::allowed()) {
            return sprintf('Запис у задачі заборонено: потрібна змінна середовища процесу %s=1.', self::ENV);
        }

        $database = (string) $db->getDatabase();

        if ($confirm === null || $confirm !== $database) {
            return sprintf('Підтвердіть базу: --confirm=%s', $database);
        }

        return null;
    }
}
