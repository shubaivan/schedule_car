<?php

namespace App\Supply\Enum;

/**
 * Життєвий цикл заявки на постачання.
 *
 * Ланцюжок розведено між двома ролями (вимога клієнта від 19.08.2026): рішення
 * про гроші ухвалює директор — «Підтверджено», «В списку очікування»,
 * «Відхилена»; далі заявку веде менеджер. Тому «Підтверджено» і «Оплачено» —
 * різні статуси: перше каже «платимо», друге — «гроші пішли».
 *
 * Переходи описані тут навмисно, а не в Symfony Workflow: гварди зводяться до
 * однієї перевірки ролі (див. SupplyRole::canMoveRequest). Якщо з'являться
 * умови, складніші за роль, — замінити allowedTransitions() на state_machine.
 */
enum SupplyStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    /** Закупівля порахована, лишилось рішення директора: платимо чи ні. */
    case Approval = 'approval';
    /** Директор сказав «платимо» — заявка чекає, поки менеджер проведе оплату. */
    case Approved = 'approved';
    /** Директор відклав: купуємо, але не зараз. Заявка жива, гроші не пішли. */
    case Waiting = 'waiting';
    case Paid = 'paid';
    case Delivery = 'delivery';
    case InStock = 'in_stock';
    /** Віддали з рук у руки, повз склад: для заявника це так само «привезли». */
    case Ready = 'ready';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Нова',
            self::InProgress => 'В роботі',
            self::Approval => 'На затвердженні',
            self::Approved => 'Підтверджено / На оплату',
            self::Waiting => 'В списку очікування',
            self::Paid => 'Оплачено',
            self::Delivery => 'Доставка',
            self::InStock => 'На складі',
            self::Ready => 'Готова',
            self::Rejected => 'Відхилена',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::New => '🆕',
            self::InProgress => '⚙️',
            self::Approval => '⏳',
            self::Approved => '✅',
            self::Waiting => '⏸',
            self::Paid => '💳',
            self::Delivery => '🚚',
            self::InStock => '📦',
            self::Ready => '🤝',
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
        return in_array($this, self::closedCases(), true);
    }

    /**
     * Статуси, у яких заявка вже погоджена, оплачена або везеться, тож мусить
     * бути відомо, у кого саме її купили. Це технічна гарантія вимоги клієнта:
     * заявка закривається не «просто так», а конкретною покупкою в конкретного
     * ФОПа. «В списку очікування» сюди не входить: там якраз нічого не купують.
     */
    public function requiresPurchase(): bool
    {
        return in_array($this, [
            self::Approval,
            self::Approved,
            self::Paid,
            self::Delivery,
            self::InStock,
            self::Ready,
        ], true);
    }

    /**
     * Дозволені наступні статуси.
     *
     * Витрата грошей іде тільки через «На затвердженні»: заявку з «В роботі»
     * не можна кинути ні в «Оплачено», ні відразу «На складі» — інакше готівкова
     * покупка проходила б повз директора. Після оплати пропуск кроків дозволений:
     * дрібницю привозять того ж дня, іноді просто з рук у руки.
     *
     * @return self[]
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::InProgress, self::Rejected],
            self::InProgress => [self::Approval, self::Rejected],
            // Набір директора — і тільки він.
            self::Approval => [self::Approved, self::Waiting, self::Rejected],
            // Гроші знайшлись — повертаємо на те саме рішення директора.
            self::Waiting => [self::Approval, self::Rejected],
            self::Approved => [self::Paid, self::Rejected],
            self::Paid => [self::Delivery, self::InStock, self::Ready, self::Rejected],
            self::Delivery => [self::InStock, self::Ready, self::Rejected],
            self::InStock => [],
            self::Ready => [],
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
        return [
            self::New,
            self::InProgress,
            self::Approval,
            self::Approved,
            self::Waiting,
            self::Paid,
            self::Delivery,
        ];
    }

    /**
     * Заявка доїхала до заявника: на склад або з рук у руки.
     *
     * Для звітів це один результат — «закрито», тож рахувати треба обидва.
     *
     * @return self[]
     */
    public static function closedCases(): array
    {
        return [self::InStock, self::Ready];
    }
}
