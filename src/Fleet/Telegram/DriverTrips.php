<?php

namespace App\Fleet\Telegram;

use App\Fleet\Service\TripFormatter;
use App\Repository\CarDriverRepository;
use App\Repository\ScheduledSetRepository;
use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use DateTime;
use DateTimeZone;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «🚚 Мої рейси» — завдання водія: хто, коли й куди його везе.
 *
 * Раніше цей екран існував лише як команда без жодної кнопки — водій просто не
 * мав як його відкрити.
 */
class DriverTrips
{
    public function __construct(
        private CarDriverRepository $carDrivers,
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
        $link = $user !== null ? $this->carDrivers->findOneByDriver($user) : null;
        $back = InlineKeyboardMarkup::make()->addRow(...StartCommand::navRow(FleetCallback::MENU, '⬅️ Автопарк'));

        if ($link === null) {
            $this->screen->render(
                $bot,
                "🚚 <b>Мої рейси</b>\n\nЗа вами не закріплено машину. Якщо ви водій — попросіть керівника внести ваш номер у довіднику автопарку.",
                $back,
            );

            return;
        }

        $car = $link->getCar();
        $sets = $this->repository->findUpcomingByCar($car, new DateTime('today', new DateTimeZone('Europe/Kyiv')));

        $lines = [sprintf('🚚 <b>Мої рейси</b> — %s', $this->formatter->escape($car->label()))];

        if (! $sets) {
            $lines[] = '';
            $lines[] = 'Поїздок поки немає. Щойно машину забронюють — бот напише.';
        }

        foreach ($sets as $set) {
            $lines[] = '';
            $lines[] = $this->formatter->driverLine($set);
        }

        $this->screen->render($bot, implode("\n", $lines), $back);
    }
}
