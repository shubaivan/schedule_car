<?php

namespace App\Fleet\Telegram;

use App\Fleet\Service\TripFormatter;
use App\Repository\ScheduledSetRepository;
use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use DateTime;
use DateTimeZone;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** «🚗 Мої поїздки» — те, що людина забронювала сама, з кнопкою скасувати. */
class MyTrips
{
    public function __construct(
        private ScheduledSetRepository $repository,
        private TelegramUserService $telegramUserService,
        private TripFormatter $formatter,
        private ChatScreen $screen,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $this->show($bot);
    }

    public function show(Nutgram $bot): void
    {
        $user = $this->telegramUserService->getCurrentUser();

        if ($user === null) {
            $this->screen->render($bot, '⚠️ Натисніть /start, щоб бот вас упізнав.');

            return;
        }

        $sets = $this->repository->findUpcomingByUser($user, new DateTime('today', new DateTimeZone('Europe/Kyiv')));

        $lines = ['🚗 <b>Мої поїздки</b>'];
        $markup = InlineKeyboardMarkup::make();

        if (! $sets) {
            $lines[] = '';
            $lines[] = 'Ви ще нічого не бронювали.';
        }

        foreach ($sets as $set) {
            $lines[] = '';
            $lines[] = sprintf(
                '%s %s · <b>%s</b> → %s',
                $this->formatter->day($set->getScheduledDateTime()),
                $this->formatter->hour($set),
                $this->formatter->escape($set->getCar()->getCarNumber()),
                $this->formatter->escape($this->formatter->destinationOf($set)),
            );

            $task = $set->getTask();

            if ($task !== null && $task !== '') {
                $lines[] = '    ↳ ' . $this->formatter->escape($task);
            }

            $markup->addRow(InlineKeyboardButton::make(
                sprintf(
                    '✖️ %s %s · %s',
                    $this->formatter->day($set->getScheduledDateTime()),
                    $this->formatter->hour($set),
                    $set->getCar()->getCarNumber(),
                ),
                callback_data: FleetCallback::cancel((int) $set->getId()),
            ));
        }

        $markup
            ->addRow(InlineKeyboardButton::make('➕ Забронювати', callback_data: FleetCallback::BOOK))
            ->addRow(...StartCommand::navRow(FleetCallback::MENU, '⬅️ Автопарк'));

        $this->screen->render($bot, implode("\n", $lines), $markup);
    }
}
