<?php

namespace App\Telegram\Access;

use App\Service\TelegramUserService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Глобальний фільтр доступу: до заявок і бронювань потрапляють лише
 * підтверджені працівники. Незнайомій людині бот пропонує зареєструватись.
 *
 * Контакт пропускаємо завжди — це і є сама реєстрація.
 */
class RequireApproval
{
    public function __construct(
        private TelegramUserService $telegramUserService,
        #[Autowire('%env(APP_COMPANY_NAME)%')]
        private string $company,
    ) {
    }

    public function __invoke(Nutgram $bot, callable $next): void
    {
        $user = $this->telegramUserService->getCurrentUser();

        if ($user === null || $bot->message()?->contact !== null || $user->isApproved()) {
            $next($bot);

            return;
        }

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        if ($user->getPhoneNumber() === null) {
            $bot->sendMessage(
                text: sprintf("👋 Вітаємо! Це бот «%s».\n", $this->company)
                    . 'Щоб подавати заявки, поділіться своїм номером — менеджер підтвердить доступ.',
                parse_mode: ParseMode::HTML,
                reply_markup: ShareContact::keyboard(),
            );

            return;
        }

        $bot->sendMessage(
            text: $user->isRejected()
                ? '⛔ Доступ до бота не надано. Зверніться до керівництва підприємства.'
                : '⏳ Ваша реєстрація ще на розгляді в менеджера. Щойно її підтвердять — бот напише вам.',
        );
    }
}
