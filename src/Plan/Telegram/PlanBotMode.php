<?php

namespace App\Plan\Telegram;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Бот цього розгортання — бот «Плану» (@zavod_buddetal_plan_bot), а не бот заявок.
 *
 * Код ботів один: який саме бот, вирішує `.env.local` розгортання
 * (TELEGRAM_TOKEN / TELEGRAM_BOT_USERNAME / TELEGRAM_WEBHOOK_SECRET). На Плані
 * ще й PLAN_TELEGRAM_BOT=1 — тоді бот лише сповіщає: прив'язка з профілю,
 * /start /mute /help, під кожним повідомленням одна кнопка «Відкрити» (https),
 * без меню заявок і без реєстрації за номером. Стенди заявок (дефолт 0)
 * лишаються зі своїми ботами й поведінкою без змін.
 */
final class PlanBotMode
{
    public function __construct(
        #[Autowire('%env(bool:default::PLAN_TELEGRAM_BOT)%')]
        private readonly bool $enabled = false,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** Кнопка «Відкрити» — лише на https (Telegram не відкриває localhost/http). */
    public static function openButton(string $absoluteUrl, string $label = 'Відкрити'): ?InlineKeyboardMarkup
    {
        return str_starts_with($absoluteUrl, 'https://')
            ? InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make($label, url: $absoluteUrl))
            : null;
    }
}
