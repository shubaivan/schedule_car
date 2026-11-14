<?php

namespace App\Plan\Notification;

/** Куди доставляти: центр сповіщень (+ тост), Telegram-бот Плану, Web Push. */
enum Channel: string
{
    case App = 'app';
    case Telegram = 'telegram';
    case Push = 'push';

    public function label(): string
    {
        return match ($this) {
            self::App => 'У Плані',
            self::Telegram => 'Telegram',
            self::Push => 'Push',
        };
    }
}
