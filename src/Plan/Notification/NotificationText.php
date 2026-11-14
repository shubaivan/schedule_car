<?php

namespace App\Plan\Notification;

use App\Plan\Event\ApprovalRequested;
use App\Plan\Event\CommentAdded;
use App\Plan\Event\DriveAuthBroken;
use App\Plan\Event\FileAdded;
use App\Plan\Event\FileRemoved;
use App\Plan\Event\ItemCreated;
use App\Plan\Event\LeadReceived;
use App\Plan\Event\MemberAdded;
use App\Plan\Event\PlanEvent;
use App\Plan\Event\PurchaseRecorded;
use App\Plan\Event\PurchaseRemoved;
use App\Plan\Event\ShareCreated;
use App\Plan\Event\StatusEvent;
use App\Plan\Event\StockMoved;
use App\Plan\Event\SubjectCreated;
use App\Plan\Subject\SubjectType;
use App\Supply\Enum\SupplyStatus;
use App\Tasks\Enum\TaskStatus;

/**
 * Короткий текст сповіщення для центру, тосту й push: заголовок події
 * («Вас згадали», «Потрібне ваше рішення», «Нова → В роботі») і рядок деталей.
 * Телефон ліда сюди не потрапляє ніколи — лише на сторінці ліда.
 */
final class NotificationText
{
    /**
     * $summary — суть складського руху («ПК 60-12-8 × 12 шт → ЖК «Лісовий»»), якщо
     * викликач її знає: назва документа вже є в заголовку картки, повторювати її не треба.
     *
     * @return array{headline: string, detail: string}
     */
    public static function of(PlanEvent $event, EventGroup $group, ?string $actor, ?string $summary = null): array
    {
        $who = $actor !== null && $actor !== '' ? $actor . ': ' : '';

        $headline = match (true) {
            $group === EventGroup::Mention => 'Вас згадали',
            $event instanceof ApprovalRequested => 'Потрібне ваше рішення',
            $event instanceof SubjectCreated => $event->subject->type === SubjectType::Supply ? 'Нова заявка' : 'Нова задача',
            $event instanceof MemberAdded => 'Вас додали до задачі',
            $event instanceof CommentAdded => 'Новий коментар',
            $event instanceof FileAdded => 'Новий файл',
            $event instanceof FileRemoved => 'Файл прибрано',
            $event instanceof StatusEvent => self::status($event),
            $event instanceof PurchaseRecorded => $event->updated ? 'Закупівлю виправлено' : 'Закупівлю записано',
            $event instanceof PurchaseRemoved => 'Закупівлю скасовано',
            $event instanceof StockMoved => 'Рух на складі',
            $event instanceof ItemCreated => 'Нова позиція складу',
            $event instanceof LeadReceived => 'Новий лід',
            $event instanceof ShareCreated => 'Вам поділились файлом',
            $event instanceof DriveAuthBroken => 'Google Диск відкликав доступ',
            default => 'Оновлення',
        };

        $detail = match (true) {
            $event instanceof CommentAdded => $who . self::clip($event->excerpt !== '' ? $event->excerpt : ($event->files > 0 ? '📎 файл' : '')),
            $event instanceof FileAdded, $event instanceof FileRemoved => $who . self::clip(implode(', ', $event->names)),
            $event instanceof StatusEvent => $who . self::clip((string) $event->comment),
            $event instanceof PurchaseRecorded => $who . self::clip(trim($event->supplier . ' · ' . $event->total, ' ·')),
            $event instanceof PurchaseRemoved => $who . self::clip(trim($event->supplier . ' · ' . $event->total, ' ·')),
            $event instanceof StockMoved => $who . self::clip($summary ?? ''),
            $event instanceof ItemCreated => $who . self::clip($summary ?? ''),
            $event instanceof LeadReceived => self::clip(trim(($event->name ?? '') . ' · ' . ($event->interest ?? ''), ' ·')),
            $event instanceof ShareCreated => $who . self::clip((string) $event->note),
            $event instanceof DriveAuthBroken => 'Копіювання на Диск зупинено — потрібен новий токен',
            default => rtrim($who, ': '),
        };

        return ['headline' => $headline, 'detail' => rtrim($detail, ': ')];
    }

    private static function status(StatusEvent $event): string
    {
        $label = static function (?string $value) use ($event): string {
            if ($value === null) {
                return '—';
            }

            return match ($event->subject->type) {
                SubjectType::Task => TaskStatus::tryFrom($value)?->label() ?? $value,
                SubjectType::Supply => SupplyStatus::tryFrom($value)?->label() ?? $value,
                default => $value,
            };
        };

        return $label($event->from) . ' → ' . $label($event->to);
    }

    private static function clip(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 140 ? mb_substr($text, 0, 139) . '…' : $text;
    }
}
