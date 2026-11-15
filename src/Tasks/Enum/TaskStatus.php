<?php

namespace App\Tasks\Enum;

/**
 * Статуси угоди/задачі.
 *
 * Колонки дошки — за відповіддю клієнта 13.11.2026: «Нова заявка», «В роботі»,
 * «Виконана» (плюс «Хроніка» й «Мої повідомлення», які статусом не є).
 * Рішення директора в задачах прибрано (Олександр, 15.11.2026: «все узгоджується
 * в самому чаті — відмітка уповноваженої особи з питанням, відповідь»). Статуси
 * «На погодженні», «Погоджено», «Очікування» лишились лише для історії подій:
 * нові задачі в них не потрапляють, а старі з них виводять звичайні переходи.
 * Погодження заявок постачання (SupplyStatus) — без змін.
 */
enum TaskStatus: string
{
    case New = 'new';
    case InWork = 'in_work';
    case Approval = 'approval';
    case Approved = 'approved';
    case Waiting = 'waiting';
    case Rejected = 'rejected';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Нова заявка',
            self::InWork => 'В роботі',
            self::Approval => 'На погодженні',
            self::Approved => 'Погоджено',
            self::Waiting => 'Очікування',
            self::Rejected => 'Відхилено',
            self::Done => 'Виконана',
        };
    }

    /** Назва дії на кнопці переходу в цей статус. */
    public function action(): string
    {
        return match ($this) {
            self::New => 'Повернути в нові',
            self::InWork => 'Взяти в роботу',
            self::Approval => 'На погодження директору',
            self::Approved => 'Погодити',
            self::Waiting => 'Відкласти',
            self::Rejected => 'Відхилити',
            self::Done => 'Виконано',
        };
    }

    /** Колонка дошки: new | work | done. */
    public function column(): string
    {
        return match ($this) {
            self::New => 'new',
            self::Done, self::Rejected => 'done',
            default => 'work',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Done || $this === self::Rejected;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::InWork, self::Done, self::Rejected],
            self::InWork => [self::Done, self::New, self::Rejected],
            // Колишні статуси погодження — лише вихід у звичайну роботу.
            self::Approval, self::Waiting, self::Approved => [self::InWork, self::Done, self::Rejected],
            self::Rejected => [self::InWork],
            self::Done => [self::InWork],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
