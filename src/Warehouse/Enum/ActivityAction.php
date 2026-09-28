<?php

namespace App\Warehouse\Enum;

/** Що сталося — рядок журналу складу. */
enum ActivityAction: string
{
    case Scan = 'scan';
    case Link = 'link';
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Move = 'move';
    case DocumentAdd = 'doc_add';
    case DocumentRemove = 'doc_remove';
    case Labels = 'labels';

    public function label(): string
    {
        return match ($this) {
            self::Scan => 'Сканував QR',
            self::Link => 'Перейшов за посиланням',
            self::View => 'Відкрив картку',
            self::Create => 'Створив',
            self::Update => 'Змінив',
            self::Move => 'Записав рух',
            self::DocumentAdd => 'Додав документ',
            self::DocumentRemove => 'Прибрав документ',
            self::Labels => 'Друкував наклейки',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Scan => '📷',
            self::Link => '🔗',
            self::View => '👁',
            self::Create => '➕',
            self::Update => '✏️',
            self::Move => '🔁',
            self::DocumentAdd => '📎',
            self::DocumentRemove => '🗑',
            self::Labels => '🖨',
        };
    }
}
