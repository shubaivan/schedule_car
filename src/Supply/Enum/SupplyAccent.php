<?php

namespace App\Supply\Enum;

/**
 * Мітка менеджера для керівника: наскільки терміново дивитись саме цю заявку.
 *
 * З'явилась замість самостійного «Терміново» (вимога клієнта від 21.08.2026):
 * строк ставить заявник і рано чи пізно починає ставити найближчу дату всім
 * підряд, тож пріоритет має призначати той, хто бачить усі заявки разом, —
 * менеджер із постачання.
 */
enum SupplyAccent: string
{
    case None = 'none';
    case Red = 'red';
    case Yellow = 'yellow';
    case Green = 'green';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Без мітки',
            self::Red => 'Терміновий рахунок',
            self::Yellow => 'Звернути увагу',
            self::Green => 'Може почекати',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::None => '',
            self::Red => '🔴',
            self::Yellow => '🟡',
            self::Green => '🟢',
        };
    }

    public function isSet(): bool
    {
        return $this !== self::None;
    }

    /** Мітки, які менеджер справді ставить, — без «порожньої». */
    public static function marks(): array
    {
        return [self::Red, self::Yellow, self::Green];
    }
}
