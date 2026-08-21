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
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

/**
 * Бронювання машини: машина → день → час → куди їде → завдання.
 *
 * Живе в одному повідомленні, як і форма заявки на матеріали: раніше кожен крок
 * слав нове («Машина №…», «Місяць 08», «День 19»), і чат перетворювався на
 * стрічку сміття, у якій ще й лишались робочі кнопки минулих кроків.
 *
 * Маршрут питаємо обов'язково: саме він робить розклад календарем завантаження,
 * а не переліком зайнятих годин — видно не тільки що машина й водій зайняті, а
 * й куди вони поїхали. Завдання лишається необов'язковим уточненням.
 */
class BookCarConversation extends Conversation
{
    private const CAR_PREFIX = 'c:';
    private const DAY_PREFIX = 'd:';
    private const HOUR_PREFIX = 'h:';
    private const SKIP = 'skip';

    /** Скільки днів пропонуємо: два тижні вперед вистачає для планування. */
    private const DAYS_OFFERED = 14;
    private const DAYS_PER_ROW = 3;
    private const HOURS_PER_ROW = 4;
    /** Робочий діапазон, у який пропонуємо машину. */
    private const FIRST_HOUR = 6;
    private const LAST_HOUR = 21;

    protected ?string $step = 'askCar';

    public ?int $carId = null;
    public ?string $date = null;
    public ?int $hour = null;
    public ?string $destination = null;

    public function __construct(
        private CarRepository $cars,
        private ScheduledSetRepository $sets,
        private TelegramUserService $telegramUserService,
        private TripFormatter $formatter,
        private FleetNotifier $notifier,
        private ChatScreen $screen,
        private MyTrips $myTrips,
        private ValidatorInterface $validator,
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

        // «⬅️ Інший день»: поки триває розмова, цей callback приходить сюди, а не
        // в загальний маршрут, тож повертаємо календар руками.
        if ($data === FleetCallback::BOOK) {
            $bot->answerCallbackQuery();
            $this->render($bot, 'На який день?', $this->dayKeyboard());
            $this->next('readDay');

            return;
        }

        if (! str_starts_with($data, self::HOUR_PREFIX)) {
            $this->askHour($bot);

            return;
        }

        $bot->answerCallbackQuery();
        $this->hour = (int) substr($data, strlen(self::HOUR_PREFIX));

        $this->askDestination($bot);
    }

    public function readDestination(Nutgram $bot): void
    {
        if ($this->cancelled($bot)) {
            return;
        }

        // Кнопок тут немає: маршрут пишуть словами. Будь-який callback — це
        // натиснута кнопка попереднього екрана, тож просто питаємо ще раз.
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
            $this->askDestination($bot);

            return;
        }

        $destination = trim((string) $bot->message()?->text);
        $this->forgetUserMessage($bot);

        if ($destination === '') {
            $this->askDestination($bot);

            return;
        }

        $this->destination = $destination;

        $this->render(
            $bot,
            'Що саме потрібно зробити? Напишіть коротко — це побачать усі в розкладі, наприклад: <i>відвезти арматуру</i>',
            InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('Пропустити', callback_data: self::SKIP),
            ),
        );
        $this->next('readTask');
    }

    private function askDestination(Nutgram $bot): void
    {
        $this->render(
            $bot,
            'Куди їде машина? Напишіть адресу або обʼєкт, наприклад: <i>вул. Заводська, 5</i>',
        );
        $this->next('readDestination');
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
            ->setDestination($this->destination)
            ->setTask($task);

        // Ліміт восьми годин на день живе окремим правилом на самій сутності —
        // питаємо його, а не дублюємо тут ще одну перевірку.
        $violations = $this->validator->validate($set);

        if ($violations->count() > 0) {
            $this->render($bot, '⚠️ ' . $this->formatter->escape($violations->get(0)->getMessage()), $this->hourKeyboard($when));
            $this->next('readHour');

            return;
        }

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

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $markup = $this->hourKeyboard($day);
        $text = $this->freeHours($day) === []
            // Найчастіше це «сьогодні» після робочого дня: годин уже не лишилось.
            ? sprintf('%s — вільних годин уже немає. Оберіть інший день:', $this->formatter->day($day))
            : $this->dayPicture($day);

        $this->render($bot, $text, $markup);
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

    /**
     * Вільні години дня: минулі на сьогодні відпадають, зайняті теж.
     *
     * @return int[]
     */
    private function freeHours(DateTime $day): array
    {
        $now = new DateTime('now', new DateTimeZone('Europe/Kyiv'));
        $isToday = $day->format('Y-m-d') === $now->format('Y-m-d');
        $from = $isToday ? (int) $now->format('H') + 1 : self::FIRST_HOUR;

        $free = [];

        for ($hour = max($from, self::FIRST_HOUR); $hour <= self::LAST_HOUR; ++$hour) {
            if (! $this->taken($this->carId, (clone $day)->setTime($hour, 0))) {
                $free[] = $hour;
            }
        }

        return $free;
    }

    private function hourKeyboard(DateTime $day): InlineKeyboardMarkup
    {
        $markup = InlineKeyboardMarkup::make();
        $row = [];

        foreach ($this->freeHours($day) as $hour) {
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
        $markup->addRow(InlineKeyboardButton::make('✖️ Скасувати', callback_data: FleetCallback::FORM_CANCEL));

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

        if ($this->destination !== null) {
            $lines[] = '✅ Куди: <b>' . $this->formatter->escape($this->destination) . '</b>';
        }

        return implode("\n", $lines);
    }

    private function cancelled(Nutgram $bot): bool
    {
        if (! $bot->isCallbackQuery() || ($bot->callbackQuery()->data ?? '') !== FleetCallback::FORM_CANCEL) {
            return false;
        }

        $bot->answerCallbackQuery();
        $this->end();

        // Саме show(), а не __invoke: той відповів би на цей самий callback
        // удруге, а Telegram на це кидає помилку — і форма вмирала б із 500.
        $this->myTrips->show($bot);

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
