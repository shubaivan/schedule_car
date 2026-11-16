<?php

namespace App\Plan\Audit;

use App\Plan\Event\ApprovalRequested;
use App\Plan\Event\CommentAdded;
use App\Plan\Event\CommentDeleted;
use App\Plan\Event\CommentEdited;
use App\Plan\Event\DriveAuthBroken;
use App\Plan\Event\FileAdded;
use App\Plan\Event\FileDownloaded;
use App\Plan\Event\FileRemoved;
use App\Plan\Event\ItemCreated;
use App\Plan\Event\JoinDecided;
use App\Plan\Event\JoinRequested;
use App\Plan\Event\LeadReceived;
use App\Plan\Event\LoginFailed;
use App\Plan\Event\MemberAdded;
use App\Plan\Event\MemberRemoved;
use App\Plan\Event\MentionAdded;
use App\Plan\Event\PurchaseRecorded;
use App\Plan\Event\PurchaseRemoved;
use App\Plan\Event\ShareCreated;
use App\Plan\Event\ShareRevoked;
use App\Plan\Event\StaffChanged;
use App\Plan\Event\StatusChanged;
use App\Plan\Event\StockMoved;
use App\Plan\Event\SubjectClosed;
use App\Plan\Event\SubjectCreated;
use App\Plan\Event\SubjectUpdated;
use App\Plan\Event\SubjectViewed;
use App\Plan\Event\UserLoggedIn;
use App\Plan\Subject\SubjectType;

/**
 * Довідник журналу аудиту: людські назви типів подій, групи для фільтра,
 * назви типів суб'єктів і клас утримання (скільки рядок живе).
 *
 * Новий тип події в PlanEventTypes → сюди ж (AuditCatalogTest не дасть забути).
 */
final class AuditCatalog
{
    /** Групи фільтра «Тип події» → типи plan_event. */
    public const array GROUPS = [
        'create' => ['label' => 'Створення', 'types' => [SubjectCreated::TYPE, ItemCreated::TYPE, LeadReceived::TYPE]],
        'update' => ['label' => 'Зміни', 'types' => [SubjectUpdated::TYPE]],
        'status' => ['label' => 'Статуси й закриття', 'types' => [StatusChanged::TYPE, ApprovalRequested::TYPE, SubjectClosed::TYPE]],
        'comment' => ['label' => 'Коментарі', 'types' => [CommentAdded::TYPE, CommentEdited::TYPE, CommentDeleted::TYPE, MentionAdded::TYPE]],
        'members' => ['label' => 'Учасники', 'types' => [MemberAdded::TYPE, MemberRemoved::TYPE]],
        'file' => ['label' => 'Файли', 'types' => [FileAdded::TYPE, FileRemoved::TYPE]],
        'money' => ['label' => 'Закупівлі й склад', 'types' => [PurchaseRecorded::TYPE, PurchaseRemoved::TYPE, StockMoved::TYPE]],
        'access' => ['label' => 'Скачування й поширення', 'types' => [FileDownloaded::TYPE, ShareCreated::TYPE, ShareRevoked::TYPE]],
        'view' => ['label' => 'Перегляди й скани', 'types' => [SubjectViewed::TYPE]],
        'login' => ['label' => 'Входи', 'types' => [UserLoggedIn::TYPE, LoginFailed::TYPE, JoinRequested::TYPE, JoinDecided::TYPE]],
        'staff' => ['label' => 'Співробітники й ролі', 'types' => [StaffChanged::TYPE]],
        'system' => ['label' => 'Службові', 'types' => [DriveAuthBroken::TYPE]],
    ];

    /** Тип події → назва в журналі. */
    public const array TYPES = [
        SubjectCreated::TYPE => 'Створено',
        ItemCreated::TYPE => 'Нова позиція складу',
        LeadReceived::TYPE => 'Новий лід',
        SubjectUpdated::TYPE => 'Змінено',
        StatusChanged::TYPE => 'Статус змінено',
        ApprovalRequested::TYPE => 'На погодження',
        SubjectClosed::TYPE => 'Закрито',
        CommentAdded::TYPE => 'Коментар',
        CommentEdited::TYPE => 'Коментар виправлено',
        CommentDeleted::TYPE => 'Коментар видалено',
        MentionAdded::TYPE => 'Згадка',
        MemberAdded::TYPE => 'Учасників додано',
        MemberRemoved::TYPE => 'Учасників прибрано',
        FileAdded::TYPE => 'Файл додано',
        FileRemoved::TYPE => 'Файл прибрано',
        PurchaseRecorded::TYPE => 'Закупівля',
        PurchaseRemoved::TYPE => 'Закупівлю прибрано',
        StockMoved::TYPE => 'Рух складу',
        FileDownloaded::TYPE => 'Файл скачано',
        ShareCreated::TYPE => 'Файл поширено',
        ShareRevoked::TYPE => 'Посилання відкликано',
        DriveAuthBroken::TYPE => 'Google Диск відкликав доступ',
        SubjectViewed::TYPE => 'Перегляд',
        UserLoggedIn::TYPE => 'Вхід',
        LoginFailed::TYPE => 'Невдалий вхід',
        StaffChanged::TYPE => 'Картку співробітника змінено',
        JoinRequested::TYPE => 'Заявка на приєднання',
        JoinDecided::TYPE => 'Рішення щодо заявки',
    ];

    public const array SUBJECTS = [
        SubjectType::Task->value => 'Задача',
        SubjectType::Supply->value => 'Постачання',
        SubjectType::WhItem->value => 'Склад · позиція',
        SubjectType::WhMovement->value => 'Склад · рух',
        SubjectType::WhClient->value => 'Склад · клієнт',
        SubjectType::WhSite->value => "Склад · об'єкт",
        SubjectType::WhCategory->value => 'Склад · категорія',
        SubjectType::Lead->value => 'Лід',
        SubjectType::User->value => 'Людина',
    ];

    /**
     * Скільки днів живуть службові події журналу (PlanEventTypes::AUDIT_ONLY: входи,
     * перегляди, скачування, поширення, службові, зміни карток людей). Доменна історія —
     * дії із задачами, заявками й складом, на ній Хроніка — не видаляється.
     * Рішення Івана 15.11: пів року, поки інструмент обкатується. Перевизначається
     * PLAN_AUDIT_RETENTION_DAYS.
     */
    public const int DEFAULT_RETENTION_DAYS = 180;

    public static function label(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }

    public static function groupOf(string $type): ?string
    {
        foreach (self::GROUPS as $group => $definition) {
            if (in_array($type, $definition['types'], true)) {
                return $group;
            }
        }

        return null;
    }

    public static function subjectLabel(string $type): string
    {
        return self::SUBJECTS[$type] ?? $type;
    }

    /**
     * Групи й окремі типи з фільтра → список типів plan_event.
     *
     * @param list<string> $groups
     * @param list<string> $types
     *
     * @return list<string>
     */
    public static function expand(array $groups, array $types): array
    {
        $out = [];

        foreach ($groups as $group) {
            foreach (self::GROUPS[$group]['types'] ?? [] as $type) {
                $out[$type] = true;
            }
        }

        foreach ($types as $type) {
            if (isset(self::TYPES[$type])) {
                $out[$type] = true;
            }
        }

        return array_keys($out);
    }

    /** @return list<array{value: string, label: string, types: list<array{value: string, label: string}>}> для фільтра на фронті */
    public static function groupsForUi(): array
    {
        $out = [];

        foreach (self::GROUPS as $value => $definition) {
            $out[] = [
                'value' => $value,
                'label' => $definition['label'],
                'types' => array_map(static fn (string $type) => ['value' => $type, 'label' => self::label($type)], $definition['types']),
            ];
        }

        return $out;
    }

    /** @return list<array{value: string, label: string}> */
    public static function subjectsForUi(): array
    {
        $out = [];

        foreach (self::SUBJECTS as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }
}
