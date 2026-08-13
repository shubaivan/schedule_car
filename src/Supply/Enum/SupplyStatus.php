<?php

namespace App\Supply\Enum;

/**
 * Життєвий цикл заявки на постачання.
 *
 * Переходи описані тут навмисно, а не в Symfony Workflow: набір статусів лінійний,
 * а гварди зводяться до однієї перевірки ролі. Якщо статуси почнуть розгалужуватись —
 * замінити allowedTransitions() на state_machine.
 */
enum SupplyStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    /** Закупівля порахована, лишилось рішення директора: платимо чи ні. */
    case Approval = 'approval';
    case Paid = 'paid';
    case Delivery = 'delivery';
    case InStock = 'in_stock';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Нова',
            self::InProgress => 'В роботі',
            self::Approval => 'На затвердженні',
            self::Paid => 'Оплачено',
            self::Delivery => 'Доставка',
            self::InStock => 'На складі',
            self::Rejected => 'Відхилена',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::New => '🆕',
            self::InProgress => '⚙️',
            self::Approval => '⏳',
            self::Paid => '💳',
            self::Delivery => '🚚',
            self::InStock => '📦',
            self::Rejected => '⛔',
        };
    }

    public function labelWithEmoji(): string
    {
        return $this->emoji() . ' ' . $this->label();
    }

    /** Заявка закрита: більше нічого не очікуємо. */
    public function isFinal(): bool
    {
        return $this === self::InStock;
    }

    /**
     * Статуси, у яких заявка вже оплачена або везеться, тож мусить бути відомо,
     * у кого саме її купили. Це технічна гарантія вимоги клієнта: заявка
     * закривається не «просто так», а конкретною покупкою в конкретного ФОПа.
     */
    public function requiresPurchase(): bool
    {
        return in_array($this, [self::Approval, self::Paid, self::Delivery, self::InStock], true);
    }

    /**
     * Дозволені наступні статуси.
     *
     * Витрата грошей іде тільки через «На затвердженні»: заявку з «В роботі»
     * не можна кинути ні в «Оплачено», ні відразу «На складі» — інакше готівкова
     * покупка проходила б повз директора. Далі, коли рішення вже є, пропуск
     * кроків дозволений: дрібницю привозять на склад того ж дня.
     *
     * @return self[]
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::InProgress, self::Rejected],
            self::InProgress => [self::Approval, self::Rejected],
            self::Approval => [self::Paid, self::Delivery, self::InStock, self::Rejected],
            self::Paid => [self::Delivery, self::InStock, self::Rejected],
            self::Delivery => [self::InStock, self::Rejected],
            self::InStock => [],
            self::Rejected => [self::InProgress],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Статуси, у яких заявка ще в роботі — для фільтра «активні» в CRM. */
    public static function openCases(): array
    {
        return [self::New, self::InProgress, self::Approval, self::Paid, self::Delivery];
    }
}
