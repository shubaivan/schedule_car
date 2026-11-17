<?php

namespace App\Plan\Subject;

use InvalidArgumentException;

/**
 * Тип «суб'єкта» — того, про що подія: задача, заявка, позиція складу….
 *
 * Значення пишуться в plan_event.subject_type (varchar(16)) і в ключі маршрутів
 * фронту (`task-12`, `supply-40`), тож їх не перейменовують.
 */
enum SubjectType: string
{
    case Task = 'task';
    case Supply = 'supply';
    case WhItem = 'wh_item';
    case WhMovement = 'wh_movement';
    case WhClient = 'wh_client';
    case WhSite = 'wh_site';
    case WhCategory = 'wh_category';
    case Lead = 'lead';
    case User = 'user';
    case Counterparty = 'counterparty';
    /** Чат «Звʼязку» — лише для службових подій (Диск); у Хроніку, пошук і сповіщення не йде. */
    case Chat = 'chat';

    /** Тип складу з журналу wh_activity (item, movement, client, site, category). */
    public static function fromWarehouse(string $type): self
    {
        return match ($type) {
            'item' => self::WhItem,
            'movement' => self::WhMovement,
            'client' => self::WhClient,
            'site' => self::WhSite,
            'category' => self::WhCategory,
            default => throw new InvalidArgumentException(sprintf('Unknown warehouse subject "%s"', $type)),
        };
    }

    public function isWarehouse(): bool
    {
        return str_starts_with($this->value, 'wh_');
    }
}
