<?php

namespace App\Plan\Telegram;

use App\Entity\TelegramUser;
use App\Plan\Login\PlanLoginBot;
use App\Plan\Login\TelegramLogin;
use App\Plan\Notification\NotificationPreferences;
use App\Service\TelegramUserService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Бот «Плану» — лише сповіщення. Глобальний middleware: коли PLAN_TELEGRAM_BOT=1,
 * він відповідає на все сам і далі (меню заявок, реєстрація за номером) не пускає;
 * інакше — прозорий, стенди заявок працюють як раніше.
 *
 * Команди: /start (і /start plink_<токен> — прив'язка з профілю, /start login_<nonce> —
 * вхід через Telegram), /mute (вимкнути/увімкнути сповіщення в Telegram), /help.
 * Вхід, контакт і заявки на приєднання веде PlanLoginBot.
 */
final class PlanBot
{
    /** Меню команд бота Плану (bot:menu). */
    public const array COMMANDS = [
        'start' => 'Що вміє бот і посилання на План',
        'mute' => 'Вимкнути / увімкнути сповіщення в Telegram',
        'help' => 'Довідка',
    ];

    public function __construct(
        private readonly PlanBotMode $mode,
        private readonly TelegramLink $link,
        private readonly TelegramUserService $users,
        private readonly NotificationPreferences $prefs,
        private readonly PlanLoginBot $login,
        #[Autowire('%env(APP_BASE_URL)%')]
        private readonly string $baseUrl = 'http://localhost',
    ) {
    }

    public function __invoke(Nutgram $bot, callable $next): void
    {
        if (! $this->mode->enabled()) {
            $next($bot);

            return;
        }

        $user = $this->users->getCurrentUser();

        if ($bot->isCallbackQuery()) {
            // Кнопки входу й заявок — свої; кнопки старих повідомлень бота заявок тут не діють.
            if (! $this->login->callback($bot, $user)) {
                $bot->answerCallbackQuery();
            }

            return;
        }

        if ($bot->message()?->contact !== null) {
            $this->login->contact($bot, $user);

            return;
        }

        $text = trim((string) $bot->message()?->text);
        $linked = $user !== null && TelegramLink::isLinked($user) && $user->isApproved() && ! $user->isArchived();

        if (preg_match('~^/start(?:@\w+)?\s+' . TelegramLogin::PREFIX . '([A-Za-z0-9]+)$~', $text, $m) === 1) {
            $this->login->start($bot, $user, $m[1]);
        } elseif (preg_match('~^/start(?:@\w+)?\s+' . TelegramLink::PREFIX . '([A-Za-z0-9]+)$~', $text, $m) === 1) {
            $this->linkFrom($bot, $m[1]);
        } elseif (preg_match('~^/mute(?:@\w+)?$~', $text) === 1 && $linked) {
            $this->toggleMute($bot, $user);
        } elseif (preg_match('~^/start(?:@\w+)?$~', $text) === 1 && $linked) {
            // Знайома людина: одна кнопка «Увійти в План» — одноразовий вхід просто з бота.
            $bot->sendMessage(
                text: sprintf("👋 %s, натисніть «Увійти в План» — відкриється План, уже з входом.\n\nСюди ж приходять сповіщення: нові задачі й заявки, коментарі, погодження.\n/mute — вимкнути або ввімкнути сповіщення\n/help — довідка", htmlspecialchars($user->shortName())),
                parse_mode: ParseMode::HTML,
                reply_markup: $this->login->loginButton($user),
            );
        } elseif ($linked) {
            $this->say($bot, $this->help(), $this->url('tasks'));
        } else {
            $this->login->welcome($bot);
        }
    }

    private function linkFrom(Nutgram $bot, string $token): void
    {
        $from = $bot->user();
        $chatId = $bot->chatId();

        if ($from === null || $chatId === null) {
            return;
        }

        [$result, $user] = $this->link->consume($token, (string) $from->id, (string) $chatId);

        $this->say($bot, match ($result) {
            LinkResult::Linked => sprintf("✅ Telegram підключено, %s.\nСюди приходитимуть сповіщення Плану. Налаштувати, що саме, — у Плані: 🔔 → Налаштування.\n\n/mute — вимкнути сповіщення", htmlspecialchars((string) $user?->shortName())),
            LinkResult::AlreadyLinked => '✅ Цей Telegram уже підключено до вашого облікового запису.',
            LinkResult::Taken => '⛔ Цей Telegram уже підключено до іншого облікового запису Плану. Спершу відключіть його там (Налаштування сповіщень).',
            LinkResult::OtherTelegram => '⛔ Ваша картка в Плані вже підключена до іншого Telegram. Спершу відключіть його (План → 🔔 → Налаштування → «Відключити») або зверніться до адміністратора.',
            LinkResult::Invalid => '⌛ Посилання застаріло або вже використане. Відкрийте План → 🔔 → Налаштування → «Підключити Telegram» ще раз.',
        }, $this->url('notifications/settings'));
    }

    private function toggleMute(Nutgram $bot, TelegramUser $user): void
    {
        $on = ! $this->prefs->of((int) $user->getId())->telegram;
        $this->prefs->setTelegram($user, $on);

        $this->say($bot, $on
            ? '🔔 Сповіщення в Telegram увімкнено. /mute — вимкнути.'
            : '🔕 Сповіщення в Telegram вимкнено (у самому Плані вони лишаються). /mute — увімкнути знову.');
    }

    private function help(): string
    {
        $lines = ['<b>План · сповіщення</b>', ''];

        foreach (self::COMMANDS as $command => $description) {
            $lines[] = '/' . $command . ' — ' . $description;
        }

        $lines[] = '';
        $lines[] = 'Що саме надсилати й «тихі години» — у Плані: 🔔 Сповіщення → Налаштування.';

        return implode("\n", $lines);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/crm/' . $path;
    }

    private function say(Nutgram $bot, string $text, ?string $url = null): void
    {
        $bot->sendMessage(
            text: $text,
            parse_mode: ParseMode::HTML,
            reply_markup: $url !== null ? PlanBotMode::openButton($url) : null,
        );
    }
}
