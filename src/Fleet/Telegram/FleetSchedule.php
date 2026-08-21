<?php

namespace App\Fleet\Telegram;

use App\Entity\Car;
use App\Entity\ScheduledSet;
use App\Fleet\Service\ScheduleBoard;
use App\Fleet\Service\TripFormatter;
use App\Service\ChatScreen;
use App\Telegram\Start\Command\StartCommand;
use DateTime;
use DateTimeZone;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «📅 Розклад машин» — календар завантаження, доступний кожному.
 *
 * Це головний екран автопарку: по днях видно, чим зайнята кожна машина і її
 * водій — о котрій, куди їде, хто забронював і за яким телефоном. Так керівник
 * бачить завантаження парку, а заявник — чи є вільна машина на потрібний день,
 * не починаючи бронювання наосліп.
 */
class FleetSchedule
{
    /** Скільки днів показуємо за раз: тиждень читається без гортання. */
    private const DAYS = 7;
    /**
     * Стеля броней у повідомленні. Ліміт Telegram — 4096 символів, а бронь із
     * маршрутом, іменем, телефоном і завданням тягне під три сотні; без стелі
     * тиждень великого парку просто не намалювався б.
     */
    private const MAX_SLOTS = 20;

    public function __construct(
        private ScheduleBoard $board,
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

        $this->screen->render(
            $bot,
            implode("\n", $this->lines($from, $to)),
            $this->markup($offsetDays),
        );
    }

    /** @return string[] */
    private function lines(DateTime $from, DateTime $to): array
    {
        $lines = [sprintf(
            '📅 <b>Розклад машин</b> — %s…%s',
            $this->formatter->day($from),
            $this->formatter->day((clone $to)->modify('-1 day')),
        )];

        ['cars' => $cars, 'drivers' => $drivers, 'sets' => $sets] = $this->board->between($from, $to);

        if (! $cars) {
            $lines[] = '';
            $lines[] = 'Машин ще немає — їх вносить керівник у розділі «Автопарк» у CRM.';

            return $lines;
        }

        if (! $sets) {
            $lines[] = '';
            $lines[] = 'На ці дні всі машини вільні.';

            return $lines;
        }

        $shown = 0;

        foreach ($this->byDayAndCar($sets) as $day => $byCar) {
            $lines[] = '';
            $lines[] = '<b>' . $this->formatter->day(new DateTime($day, new DateTimeZone('Europe/Kyiv'))) . '</b>';

            foreach ($byCar as $carId => $daySets) {
                if ($shown >= self::MAX_SLOTS) {
                    $lines[] = '';
                    $lines[] = sprintf(
                        '<i>…показано перші %d бронювань із %d. Далі — гортайте тиждень уперед або дивіться календар у CRM.</i>',
                        self::MAX_SLOTS,
                        count($sets),
                    );

                    return $lines;
                }

                $lines[] = '';
                $lines[] = $this->formatter->carHeading($daySets[0]->getCar(), $drivers[$carId] ?? []);

                foreach ($daySets as $set) {
                    if ($shown >= self::MAX_SLOTS) {
                        break;
                    }

                    foreach ($this->formatter->slotLines($set) as $line) {
                        $lines[] = $line;
                    }

                    ++$shown;
                }
            }

            $free = $this->freeCars($cars, array_keys($byCar));

            if ($free !== []) {
                $lines[] = '';
                $lines[] = '🟢 вільні: ' . $this->formatter->escape(implode(', ', $free));
            }
        }

        return $lines;
    }

    /**
     * Броні тижня → дні → машини. Саме в такому порядку читають розклад:
     * спершу «що в четвер», потім «чим зайнята кожна машина».
     *
     * @param ScheduledSet[] $sets
     *
     * @return array<string, array<int, ScheduledSet[]>>
     */
    private function byDayAndCar(array $sets): array
    {
        $days = [];

        foreach ($sets as $set) {
            $days[$set->getScheduledDateTime()->format('Y-m-d')][(int) $set->getCar()->getId()][] = $set;
        }

        return $days;
    }

    /**
     * Машини, які цього дня ніхто не брав. Показуємо назвами, а не окремими
     * блоками: людині потрібен не «порожній розклад», а відповідь «на чому
     * можна поїхати».
     *
     * @param Car[] $cars
     * @param int[] $busyCarIds
     *
     * @return string[]
     */
    private function freeCars(array $cars, array $busyCarIds): array
    {
        $free = [];

        foreach ($cars as $car) {
            if (! in_array((int) $car->getId(), $busyCarIds, true)) {
                $free[] = (string) $car->getCarNumber();
            }
        }

        return $free;
    }

    private function markup(int $offsetDays): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
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
