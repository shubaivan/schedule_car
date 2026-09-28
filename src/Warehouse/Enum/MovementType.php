<?php

namespace App\Warehouse\Enum;

/**
 * Рух майна. Кожен тип задає, звідки й куди він може йти:
 *
 *   Надходження   ззовні      → склад
 *   Відвантаження склад       → об'єкт
 *   Повернення    об'єкт      → склад
 *   Переміщення   будь-яке    → будь-яке інше
 *   Списання      будь-яке    → нікуди
 */
enum MovementType: string
{
    case Receipt = 'receipt';
    case Shipment = 'shipment';
    case Return = 'return';
    case Transfer = 'transfer';
    case WriteOff = 'writeoff';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Надходження',
            self::Shipment => 'Відвантаження',
            self::Return => 'Повернення',
            self::Transfer => 'Переміщення',
            self::WriteOff => 'Списання',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Receipt => '📥',
            self::Shipment => '📤',
            self::Return => '↩️',
            self::Transfer => '🔁',
            self::WriteOff => '🗑',
        };
    }

    /** Чи потрібне місце «звідки». Надходження приходить ззовні. */
    public function needsFrom(): bool
    {
        return $this !== self::Receipt;
    }

    /** Чи потрібне місце «куди». Списане не їде нікуди. */
    public function needsTo(): bool
    {
        return $this !== self::WriteOff;
    }

    /** Якого роду має бути «звідки»; null — будь-яке місце. */
    public function fromKind(): ?SiteKind
    {
        return match ($this) {
            self::Shipment => SiteKind::Warehouse,
            self::Return => SiteKind::Site,
            default => null,
        };
    }

    /** Якого роду має бути «куди»; null — будь-яке місце. */
    public function toKind(): ?SiteKind
    {
        return match ($this) {
            self::Receipt, self::Return => SiteKind::Warehouse,
            self::Shipment => SiteKind::Site,
            default => null,
        };
    }
}
