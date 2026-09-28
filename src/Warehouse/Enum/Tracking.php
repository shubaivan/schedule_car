<?php

namespace App\Warehouse\Enum;

/**
 * Як рахуємо позицію.
 *
 * Поштучно — дорога річ зі своїм QR-кодом: щит опалубки, трактор. Вона завжди
 * в одному місці, і картка знає, де саме.
 *
 * Кількістю — дрібниця, яку ніхто не клеїть по одній: замки, стяжки, фіксатори.
 * Один QR на тип, а залишок рахується по кожному місцю окремо.
 */
enum Tracking: string
{
    case Unit = 'unit';
    case Bulk = 'bulk';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Поштучно',
            self::Bulk => 'Кількістю',
        };
    }
}
