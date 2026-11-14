<?php

namespace App\Plan\Search;

use App\Supply\Enum\SupplyStatus;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Enum\WorkRole;
use App\Warehouse\Enum\ItemState;
use App\Warehouse\Enum\MovementType;
use BackedEnum;

/**
 * Довідник пошуку: які типи документів є, як вони групуються у фасет «тип»
 * і як підписати статус `<тип>:<значення>` людською мовою.
 */
final class SearchCatalog
{
    /** Групи фасета «тип» → типи документів (doc_type). Порядок — порядок у фасеті. */
    public const array GROUPS = [
        'task' => ['task'],
        'supply' => ['supply'],
        'warehouse' => ['wh_item', 'wh_movement', 'wh_client', 'wh_site'],
        'comment' => ['comment', 'supply_comment'],
        'file' => ['file', 'supply_file', 'wh_document'],
        'person' => ['person'],
    ];

    public const array GROUP_LABELS = [
        'task' => 'Задачі',
        'supply' => 'Заявки',
        'warehouse' => 'Склад',
        'comment' => 'Коментарі',
        'file' => 'Файли',
        'person' => 'Люди',
    ];

    public const array DOC_LABELS = [
        'task' => 'Задача',
        'comment' => 'Коментар',
        'file' => 'Файл',
        'supply' => 'Заявка',
        'supply_comment' => 'Коментар до заявки',
        'supply_file' => 'Файл заявки',
        'wh_item' => 'Позиція складу',
        'wh_movement' => 'Рух складу',
        'wh_client' => 'Клієнт складу',
        'wh_site' => "Об'єкт",
        'wh_document' => 'Документ складу',
        'person' => 'Людина',
    ];

    /** Групи статусів у фасеті — за типом суб'єкта. */
    public const array STATUS_KINDS = [
        'task' => 'Задача',
        'supply' => 'Заявка',
        'wh_item' => 'Майно',
        'wh_movement' => 'Рух',
        'person' => 'Роль',
    ];

    /** @return list<string> */
    public static function docTypes(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    public static function groupOf(string $docType): string
    {
        foreach (self::GROUPS as $group => $types) {
            if (in_array($docType, $types, true)) {
                return $group;
            }
        }

        return $docType;
    }

    /**
     * Фільтр «тип»: групи (task, warehouse…) або конкретні типи документів.
     *
     * @param list<string> $values
     *
     * @return list<string> doc_type
     */
    public static function expand(array $values): array
    {
        $out = [];

        foreach ($values as $value) {
            if (isset(self::GROUPS[$value])) {
                array_push($out, ...self::GROUPS[$value]);
            } elseif (in_array($value, self::docTypes(), true)) {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    /** `task:in_work` → «В роботі»; невідоме — як є. */
    public static function statusLabel(string $key): string
    {
        [$kind, $value] = array_pad(explode(':', $key, 2), 2, '');

        $label = match ($kind) {
            'task' => TaskStatus::tryFrom($value)?->label(),
            'supply' => SupplyStatus::tryFrom($value)?->label(),
            'wh_item' => ItemState::tryFrom($value)?->label(),
            'wh_movement' => MovementType::tryFrom($value)?->label(),
            'person' => WorkRole::tryFrom($value)?->label(),
            default => null,
        };

        return $label ?? $value;
    }

    /** Закрите (закреслюємо в результатах): завершена задача, закрита заявка, списане майно. */
    public static function isClosed(?string $key): bool
    {
        if ($key === null) {
            return false;
        }

        [$kind, $value] = array_pad(explode(':', $key, 2), 2, '');

        return match ($kind) {
            'task' => TaskStatus::tryFrom($value)?->isClosed() ?? false,
            'supply' => SupplyStatus::tryFrom($value)?->isFinal() ?? false,
            'wh_item' => $value === ItemState::WrittenOff->value,
            default => false,
        };
    }

    /**
     * Усі статуси, які можна обрати у фільтрі, — навіть якщо зараз нуль збігів.
     *
     * @return list<array{key: string, label: string, kind: string}>
     */
    public static function statuses(): array
    {
        $out = [];

        foreach (TaskStatus::cases() as $s) {
            $out[] = ['key' => 'task:' . $s->value, 'label' => $s->label(), 'kind' => 'task'];
        }

        foreach (SupplyStatus::cases() as $s) {
            $out[] = ['key' => 'supply:' . $s->value, 'label' => $s->label(), 'kind' => 'supply'];
        }

        foreach (ItemState::cases() as $s) {
            $out[] = ['key' => 'wh_item:' . $s->value, 'label' => $s->label(), 'kind' => 'wh_item'];
        }

        foreach (MovementType::cases() as $s) {
            $out[] = ['key' => 'wh_movement:' . $s->value, 'label' => $s->label(), 'kind' => 'wh_movement'];
        }

        return $out;
    }

    /**
     * SQL CASE для підпису значення enum (щоб «надходження» знаходило рух складу).
     *
     * @param list<BackedEnum> $cases
     */
    public static function caseSql(string $column, array $cases): string
    {
        $sql = 'CASE ' . $column;

        foreach ($cases as $case) {
            /** @phpstan-ignore method.notFound */
            $sql .= sprintf(" WHEN '%s' THEN '%s'", $case->value, str_replace("'", "''", $case->label()));
        }

        return $sql . " ELSE '' END";
    }
}
