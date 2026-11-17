<?php

namespace App\Plan\Planfix;

/**
 * Запобіжник проду: --apply пише в задачі лише з явною змінною середовища
 * PLANFIX_IMPORT_ALLOW=1 у самому процесі (у .env її немає навмисно).
 */
final class PlanfixImportGuard
{
    public const string ENV = 'PLANFIX_IMPORT_ALLOW';

    public static function allowed(): bool
    {
        $value = $_SERVER[self::ENV] ?? $_ENV[self::ENV] ?? getenv(self::ENV);

        return $value === '1' || $value === 1 || $value === 'true';
    }
}
