<?php

namespace App\Plan\Presence;

/**
 * Розділ «Плану», у якому людина зараз, — лише назва розділу, без того, що саме
 * відкрито (задача чи заявка можуть бути не для всіх очей, ТЗ §24).
 * Ключ надсилає фронт (plan/presence.ts); невідомий → «План».
 */
enum PresenceSection: string
{
    case Overview = 'overview';
    case Chronicle = 'chronicle';
    case Tasks = 'tasks';
    case Supply = 'supply';
    case Warehouse = 'warehouse';
    case Counterparties = 'counterparties';
    case Search = 'search';
    case Notifications = 'notifications';
    case Staff = 'staff';
    case Files = 'files';
    case Fleet = 'fleet';
    case Admin = 'admin';
    case Profile = 'profile';
    case Zvyazok = 'zvyazok';
    case Other = 'other';

    public static function fromClient(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Other) : self::Other;
    }

    public function label(): string
    {
        return match ($this) {
            self::Overview => 'Огляд',
            self::Chronicle => 'Хроніка',
            self::Tasks => 'Задачі',
            self::Supply => 'Постачання',
            self::Warehouse => 'Склад',
            self::Counterparties => 'Контрагенти',
            self::Search => 'Пошук',
            self::Notifications => 'Сповіщення',
            self::Staff => 'Люди',
            self::Files => 'Файли',
            self::Fleet => 'Автопарк',
            self::Admin => 'Адмін',
            self::Profile => 'Профіль',
            self::Zvyazok => 'Звʼязок',
            self::Other => 'План',
        };
    }
}
