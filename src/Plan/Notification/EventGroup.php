<?php

namespace App\Plan\Notification;

use App\Plan\Event\ApprovalRequested;
use App\Plan\Event\CommentAdded;
use App\Plan\Event\FileAdded;
use App\Plan\Event\FileRemoved;
use App\Plan\Event\ItemCreated;
use App\Plan\Event\LeadReceived;
use App\Plan\Event\MemberAdded;
use App\Plan\Event\PlanEvent;
use App\Plan\Event\PurchaseRecorded;
use App\Plan\Event\PurchaseRemoved;
use App\Plan\Event\StatusChanged;
use App\Plan\Event\StockMoved;
use App\Plan\Event\SubjectClosed;
use App\Plan\Event\SubjectCreated;

/**
 * Група сповіщень — одиниця налаштувань («що мені надсилати»). Подію в групу
 * переводить forEvent(); подія без групи (правка коментаря, перегляд, вхід…)
 * у центр сповіщень не потрапляє взагалі.
 *
 * Значення пишуться в notification_pref.event_group — не перейменовувати.
 */
enum EventGroup: string
{
    /** Нова задача/заявка, де ви учасник; вас додали до задачі. */
    case Assigned = 'assigned';
    /** Коментар або файл. */
    case Comment = 'comment';
    /** Вас згадали в коментарі (важливе — жовтий тост). */
    case Mention = 'mention';
    /** Зміна статусу, закриття. */
    case Status = 'status';
    /** Потрібне ваше рішення — погодження (важливе). */
    case Approval = 'approval';
    /** Закупівля по заявці записана / скасована. */
    case Purchase = 'purchase';
    /** Рух на складі, нова позиція. */
    case Warehouse = 'warehouse';
    /** Новий лід із сайту / AI-бота (важливе; потік SL). */
    case Lead = 'lead';

    public static function forEvent(PlanEvent $event): ?self
    {
        return match (true) {
            $event instanceof SubjectCreated, $event instanceof MemberAdded => self::Assigned,
            $event instanceof CommentAdded, $event instanceof FileAdded, $event instanceof FileRemoved => self::Comment,
            $event instanceof ApprovalRequested => self::Approval,
            $event instanceof StatusChanged, $event instanceof SubjectClosed => self::Status,
            $event instanceof PurchaseRecorded, $event instanceof PurchaseRemoved => self::Purchase,
            $event instanceof StockMoved, $event instanceof ItemCreated => self::Warehouse,
            $event instanceof LeadReceived => self::Lead,
            default => null,
        };
    }

    /** Жовтий тост на всю ширину, і в «тихі години» теж видно в застосунку. */
    public function important(): bool
    {
        return $this === self::Mention || $this === self::Approval || $this === self::Lead;
    }

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'Нові задачі й заявки, мене додали',
            self::Comment => 'Коментарі та файли',
            self::Mention => 'Мене згадали',
            self::Status => 'Зміна статусу',
            self::Approval => 'Потрібне моє рішення',
            self::Purchase => 'Закупівлі по заявках',
            self::Warehouse => 'Склад: рухи й нові позиції',
            self::Lead => 'Нові ліди',
        };
    }
}
