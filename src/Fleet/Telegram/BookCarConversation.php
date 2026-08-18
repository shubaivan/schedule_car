<?php

namespace App\Fleet\Telegram;

use App\Entity\ScheduledSet;
use App\Fleet\Service\FleetNotifier;
use App\Fleet\Service\TripFormatter;
use App\Repository\CarRepository;
use App\Repository\ScheduledSetRepository;
use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/**
 * Бронювання машини: машина → день → час → завдання.
 *
 * Живе в одному повідомленні, як і форма заявки на матеріали: раніше кожен крок
 * слав нове («Машина №…», «Місяць 08», «День 19»), і чат перетворювався на
 * стрічку сміття, у якій ще й лишались робочі кнопки минулих кроків.
 *
 * Завдання питаємо обов'язково не просто так: саме воно робить розклад
 * корисним для інших — видно не тільки що машина зайнята, а й навіщо.
 */
class BookCarConversation extends Conversation
{
    private const CAR_PREFIX = 'c:';
    private const DAY_PREFIX = 'd:';
    private const HOUR_PREFIX = 'h:';
    private const SKIP = 'skip';
    private const CANCEL = 'fleet:form-cancel';

    /** Скільки днів пропонуємо: два тижні вперед вистачає для планування. */
    private const DAYS_OFFERED = 14;
    private const DAYS_PER_ROW = 3;
    private const HOURS_PER_ROW = 4;

    protected ?string $step = 'askCar';

    public ?int $carId = null;
    public ?string $date = null;
    public ?int $hour = null;

    public function __construct(
        private CarRepository $cars,
        private ScheduledSetRepository $sets,
        private TelegramUserService $telegramUserService,
        private TripFormatter $formatter,
        private FleetNotifier $notifier,
        private ChatScreen $screen,
        private MyTrips $myTrips,
        private EntityManagerInterface $em,
    ) {
    }

    public function askCar(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $cars = $this->cars->findBy(['active' => true], ['carNumber' => 'ASC']);

        if (! $cars) {
            $this->screen->render(
                $bot,
                "🚗 <b>Бронювання</b>\n\nМашин ще немає — їх вносить керівник у розділі «Автопарк» у CRM.",
                InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make('⬅️ Автопарк', callback_data: FleetCallback::MENU),
                ),
            );
            $this->end();

            return;
        }

        $markup = InlineKeyboardMarkup::make();
        $row = [];

        foreach ($cars as $car) {
            $row[] = InlineKeyboardButton::make($car->label(), callback_data: self::CAR_PREFIX . $car->getId());

            if (count($row) === 2) {
                $markup->addRow(...$row);
                $row = [];
            }
        }

        if ($row) {
            $markup->addRow(...$row);
        }

        $this->render($bot, 'Яка машина потрібна?', $markup);
        $this->next('readCar');
    }

    public function readCar(Nutgram $bot): void
    {
        if ($this->cancelled($bot)) {
            return;
        }

        $data = (string) ($bot->callbackQuery()->data ?? '');

        if (! str_starts_with($data, self::CAR_PREFIX)) {
            $this->askCar($bot);

            return;
        }

        $bot->answerCallbackQuery();
        $this->carId = (int) substr($data, strlen(self::CAR_PREFIX));

        $this->render($bot, 'На який день?', $this->dayKeyboard());
        $this->next('readDay');
    }

    public function readDay(Nutgram $bot): void
    {
        if ($this->cancelled($bot)) {
            return;
        }

        $data = (string) ($bot->callbackQuery()->data ?? '');

        if (! str_starts_with($data, self::DAY_PREFIX)) {
            $this->render($bot, '⚠️ Оберіть день кнопкою:', $this->dayKeyboard());

            return;
        }

        $bot->answerCallbackQuery();
        $this->date = substr($data, strlen(self::DAY_PREFIX));

        $this->askHour($bot);
    }

    public function readHour(Nutgram $bot): void
    {
        if ($this->cancelled($bot)) {
            return;
        }

        $data = (string) ($bot->callbackQuery()->data ?? '');

        if (! str_starts_with($data, self::HOUR_PREFIX)) {
            $this->askHour($bot);

            return;
        }

        $bot->answerCallbackQuery();
        $this->hour = (int) substr($data, strlen(self::HOUR_PREFIX));

        $this->render(
            $bot,
            'Що потрібно зробити? Напишіть коротко — це побачать усі в розкладі, наприклад: <i>відвезти арматуру на Амет-Хана</i>',
            InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('Пропустити', callback_data: self::SKIP),
            ),
        );
        $this->next('readTask');
    }

    public function readTask(Nutgram $bot): void
    {
        if ($this->cancelled($bot)) {
            return;
        }

        $task = null;

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        } else {
            $task = trim((string) $bot->message()?->text) ?: null;
            $this->forgetUserMessage($bot);
        }

        $user = $this->telegramUserService->getCurrentUser();
        $car = $this->carId !== null ? $this->cars->find($this->carId) : null;

        if ($user === null || $car === null || $this->date === null || $this->hour === null) {
            $this->screen->render($bot, '⚠️ Щось загубилось. Спробуйте ще раз: /start');
            $this->end();

            return;
        }

        $when = DateTime::createFromFormat(
            'Y-m-d H:i:s',
            sprintf('%s %02d:00:00', $this->date, $this->hour),
            new DateTimeZone('Europe/Kyiv'),
        );

        if ($when === false) {
            $this->screen->render($bot, '⚠️ Не вдалось розібрати дату. Спробуйте ще раз: /start');
            $this->end();

            return;
        }

        // Поки людина писала завдання, час могли зайняти — перевіряємо ще раз.
        if ($this->taken($car->getId(), $when)) {
            $this->render($bot, '⚠️ Цю годину щойно зайняли. Оберіть іншу:', $this->hourKeyboard($when));
            $this->next('readHour');

            return;
        }

        $set = (new ScheduledSet())
            ->setCar($car)
            ->setTelegramUserId($user)
            ->setYear((int) $when->format('Y'))
            ->setMonth((int) $when->format('m'))
            ->setDay((int) $when->format('d'))
            ->setHour((int) $when->format('H'))
            ->setScheduledAt($when)
            ->setTask($task);

        $this->em->persist($set);
        $this->em->flush();

        try {
            $this->notifier->booked($set);
        } catch (Throwable) {
            // Сповіщення водієві не критичне: бронювання вже в розкладі.
        }

        $this->end();
        $this->myTrips->show($bot);
    }

    private function askHour(Nutgram $bot): void
    {
        $day = DateTime::createFromFormat(
            'Y-m-d H:i:s',
            $this->date . ' 00:00:00',
            new DateTimeZone('Europe/Kyiv'),
        );

        if ($day === false) {
            $this->askCar($bot);

            return;
        }

        $this->render($bot, $this->dayPicture($day), $this->hourKeyboard($day));
        $this->next('readHour');
    }

    /** Що вже зайнято цього дня — щоб вибирати час, бачачи сусідів. */
    private function dayPicture(DateTime $day): string
    {
        $sets = $this->sets->findBetween($day, (clone $day)->modify('+1 day'));
        $sets = array_filter($sets, fn (ScheduledSet $set) => $set->getCar()->getId() === $this->carId);

        if (! $sets) {
            return sprintf('%s — машина вільна цілий день. О котрій?', $this->formatter->day($day));
        }

        $lines = [sprintf('%s — уже зайнято:', $this->formatter->day($day)), ''];

        foreach ($sets as $set) {
            $lines[] = $this->formatter->line($set);
        }

        $lines[] = '';
        $lines[] = 'О котрій потрібна вам?';

        return implode("\n", $lines);
    }

    private function hourKeyboard(DateTime $day): InlineKeyboardMarkup
    {
        $now = new DateTime('now', new DateTimeZone('Europe/Kyiv'));
        $isToday = $day->format('Y-m-d') === $now->format('Y-m-d');
        $from = $isToday ? (int) $now->format('H') + 1 : 6;

        $markup = InlineKeyboardMarkup::make();
        $row = [];

        for ($hour = max($from, 0); $hour <= 21; ++$hour) {
            $slot = (clone $day)->setTime($hour, 0);

            if ($this->taken($this->carId, $slot)) {
                continue;
            }

            $row[] = InlineKeyboardButton::make(
                sprintf('%02d:00', $hour),
                callback_data: self::HOUR_PREFIX . $hour,
            );

            if (count($row) === self::HOURS_PER_ROW) {
                $markup->addRow(...$row);
                $row = [];
            }
        }

        if ($row) {
            $markup->addRow(...$row);
        }

        $markup->addRow(InlineKeyboardButton::make('⬅️ Інший день', callback_data: FleetCallback::BOOK));

        return $markup;
    }

    private function dayKeyboard(): InlineKeyboardMarkup
    {
        $today = new DateTime('today', new DateTimeZone('Europe/Kyiv'));
        $markup = InlineKeyboardMarkup::make();
        $row = [];

        for ($i = 0; $i < self::DAYS_OFFERED; ++$i) {
            $day = (clone $today)->modify(sprintf('+%d days', $i));

            $row[] = InlineKeyboardButton::make(
                $this->formatter->day($day),
                callback_data: self::DAY_PREFIX . $day->format('Y-m-d'),
            );

            if (count($row) === self::DAYS_PER_ROW) {
                $markup->addRow(...$row);
                $row = [];
            }
        }

        if ($row) {
            $markup->addRow(...$row);
        }

        return $markup;
    }

    private function taken(?int $carId, DateTime $slot): bool
    {
        if ($carId === null) {
            return false;
        }

        foreach ($this->sets->findBetween($slot, (clone $slot)->modify('+1 hour')) as $set) {
            if ($set->getCar()->getId() === $carId) {
                return true;
            }
        }

        return false;
    }

    /** Форма перемальовує екран, тож «Скасувати» є на кожному кроці. */
    private function render(Nutgram $bot, string $question, ?InlineKeyboardMarkup $markup = null): void
    {
        $markup ??= InlineKeyboardMarkup::make();
        $markup->addRow(InlineKeyboardButton::make('✖️ Скасувати', callback_data: self::CANCEL));

        $this->screen->render($bot, $this->summary() . "\n" . $question, $markup);
    }

    private function summary(): string
    {
        $lines = ['🚗 <b>Бронювання машини</b>', ''];

        if ($this->carId !== null) {
            $car = $this->cars->find($this->carId);

            if ($car !== null) {
                $lines[] = '✅ Машина: <b>' . $this->formatter->escape($car->label()) . '</b>';
            }
        }

        if ($this->date !== null) {
            $day = DateTime::createFromFormat('Y-m-d H:i:s', $this->date . ' 00:00:00', new DateTimeZone('Europe/Kyiv'));

            if ($day !== false) {
                $lines[] = '✅ День: <b>' . $this->formatter->day($day) . '</b>';
            }
        }

        if ($this->hour !== null) {
            $lines[] = sprintf('✅ Час: <b>%02d:00</b>', $this->hour);
        }

        return implode("\n", $lines);
    }

    private function cancelled(Nutgram $bot): bool
    {
        if (! $bot->isCallbackQuery() || ($bot->callbackQuery()->data ?? '') !== self::CANCEL) {
            return false;
        }

        $bot->answerCallbackQuery();
        $this->end();

        ($this->myTrips)($bot);

        return true;
    }

    /** Прибираємо репліку користувача: екран один, стрічки з відповідей не треба. */
    private function forgetUserMessage(Nutgram $bot): void
    {
        $message = $bot->message();

        if ($message === null) {
            return;
        }

        try {
            $bot->deleteMessage($message->chat->id, $message->message_id);
        } catch (Throwable) {
            // Не критично: повідомлення просто лишиться в чаті.
        }
    }
}
