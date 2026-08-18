<?php

namespace App\Fleet\Telegram;

use App\Fleet\Service\TripFormatter;
use App\Repository\ScheduledSetRepository;
use App\Service\ChatScreen;
use App\Telegram\Start\Command\StartCommand;
use DateTime;
use DateTimeZone;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «📅 Розклад машин» — спільна картина зайнятості, доступна кожному.
 *
 * Це головний екран автопарку: перш ніж просити машину, людина бачить, хто вже
 * її взяв, на коли, з яким завданням і за яким телефоном — і або домовляється
 * поїхати разом, або обирає інший час. Раніше про це можна було дізнатись,
 * лише почавши бронювати.
 */
class FleetSchedule
{
    /** Скільки днів показуємо за раз: тиждень читається без гортання. */
    private const DAYS = 7;

    public function __construct(
        private ScheduledSetRepository $repository,
        private TripFormatter $formatter,
        private ChatScreen $screen,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $this->show($bot, $this->offsetFrom($bot));
    }

    public function show(Nutgram $bot, int $offsetDays = 0): void
    {
        $from = new DateTime('today', new DateTimeZone('Europe/Kyiv'));

        if ($offsetDays !== 0) {
            $from->modify(sprintf('%+d days', $offsetDays));
        }

        $to = (clone $from)->modify(sprintf('+%d days', self::DAYS));
        $sets = $this->repository->findBetween($from, $to);

        $lines = [sprintf(
            '📅 <b>Розклад машин</b> — %s…%s',
            $this->formatter->day($from),
            $this->formatter->day((clone $to)->modify('-1 day')),
        )];

        if (! $sets) {
            $lines[] = '';
            $lines[] = 'На ці дні машини вільні.';
        }

        $currentDay = null;

        foreach ($sets as $set) {
            $day = $set->getScheduledDateTime()->format('Y-m-d');

            if ($day !== $currentDay) {
                $currentDay = $day;
                $lines[] = '';
                $lines[] = '<b>' . $this->formatter->day($set->getScheduledDateTime()) . '</b>';
            }

            $lines[] = $this->formatter->line($set);
        }

        $markup = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    '⬅️ Тиждень назад',
                    callback_data: FleetCallback::schedule($offsetDays - self::DAYS),
                ),
                InlineKeyboardButton::make(
                    'Тиждень вперед ➡️',
                    callback_data: FleetCallback::schedule($offsetDays + self::DAYS),
                ),
            )
            ->addRow(InlineKeyboardButton::make('➕ Забронювати', callback_data: FleetCallback::BOOK))
            ->addRow(...StartCommand::navRow(FleetCallback::MENU, '⬅️ Автопарк'));

        $this->screen->render($bot, implode("\n", $lines), $markup);
    }

    /** Зсув гортання лежить у самій кнопці — стан ніде не зберігаємо. */
    private function offsetFrom(Nutgram $bot): int
    {
        $data = (string) ($bot->callbackQuery()->data ?? '');

        if (! str_starts_with($data, FleetCallback::SCHEDULE_PREFIX)) {
            return 0;
        }

        return (int) substr($data, strlen(FleetCallback::SCHEDULE_PREFIX));
    }
}
