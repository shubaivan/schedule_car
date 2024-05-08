<?php

namespace App\Telegram\ScheduleCar\Command;

use App\Entity\ScheduledSet;
use App\Repository\CarRepository;
use App\Service\ScheduleCarService;
use App\Service\TelegramUserService;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ScheduleCar extends Conversation
{
    protected ?string $step = 'chooseCar';
    public ?string $carId;
    public ?string $month;
    public ?string $day;
    public ?string $hour;
    public ?bool $confirmPhone = false;

    public function __construct(
        protected string $projectDir,
        private ScheduleCarService $scheduleCarService,
        private EntityManagerInterface $em,
        private TelegramUserService $telegramUserService,
        private ValidatorInterface $validator,
        private CarRepository $carRepository
    ) {}

    public function chooseCar(Nutgram $bot)
    {
        if (!$this->telegramUserService->getCurrentUser()->getPhoneNumber()) {
            $this->confirmPhone = true;
            $bot->sendMessage(
                text: 'Ваш Номер',
                reply_markup: ReplyKeyboardMarkup::make(one_time_keyboard: true)->addRow(
                    KeyboardButton::make('Підтвердіть ВАШ телефон', true),
                )
            );
        } else {
            $inlineKeyboardMarkup = InlineKeyboardMarkup::make();
            $cars = [];
            foreach ($this->carRepository->findAll() as $car) {
                $cars[] = InlineKeyboardButton::make(
                    text: $car->getCarInfo(), callback_data: 'car_' . $car->getId()
                );
                if (count($cars) == 3) {
                    $inlineKeyboardMarkup->addRow(...$cars);
                    $cars = [];
                }
            }

            if (count($cars)) {
                $inlineKeyboardMarkup->addRow(...$cars);
            }

            $bot->sendMessage(
                text: 'Оберіть машину',
                reply_markup: $inlineKeyboardMarkup
            );
        }

        $this->next('chooseMonth');
    }

    public function chooseMonth(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() && !$bot->message() && !$bot->message()->contact) {
            $this->chooseCar($bot);

            return;
        }

        if ($bot->isCallbackQuery() && !str_contains($bot->callbackQuery()->data, 'car_')) {
            $this->chooseCar($bot);

            return;
        }

        if ($this->confirmPhone
            && $bot->message()
        ) {
            if ($bot->message()->contact && $bot->message()->contact->phone_number) {
                $phone_number = $bot->message()->contact->phone_number;
                $bot->sendMessage(
                    text: 'Removing keyboard...',
                    reply_markup: ReplyKeyboardRemove::make(true),
                )?->delete();
            } else {
                $this->confirmPhone = true;
                $bot->sendMessage(
                    text: 'Подтрібно натиснути',
                    reply_markup: ReplyKeyboardMarkup::make(one_time_keyboard: true)->addRow(
                        KeyboardButton::make('Підтвердіть ВАШ телефон', true),
                    )
                );
                $file = sprintf(
                    '%s/assets/img/share_contact.jpeg',
                    $this->projectDir
                );
                if (is_file($file) && is_readable($file)) {
                    $photo = fopen($file, 'r+');

                    /** @var Message $message */
                    $message = $bot->sendPhoto(
                        photo: InputFile::make($photo)
                    );
                }
                $this->next('chooseMonth');

                return;
            }
            $this->confirmPhone = false;
            $this->telegramUserService->savePhone($phone_number);

            $this->em->flush();

            $this->chooseCar($bot);
        }

        $this->carId = str_replace('car_', '', $bot->callbackQuery()->data);

        $bot->sendMessage(
            text: 'Машина №' . $this->getCarInfo()
        );
        $current = ScheduleCarService::createNewDate();
        $currentMonth = (int)$current->format('m');
        $last = (clone $current)->modify('last day of december this year');
        $lastMonth = (int)$last->format('m');

        $inlineKeyboardMarkup = InlineKeyboardMarkup::make();
        $month = [];
        for ($i = $currentMonth; $i <= $lastMonth; $i++) {
            if ($i == $currentMonth) {
                $format = $current->format('Y-m');
            } else {
                $format = $current->modify('+1 month')->format('Y-m');
            }
            $month[] = InlineKeyboardButton::make(
                text: $format, callback_data: 'month_' . $current->format('m')
            );
            if (count($month) == 3) {
                $inlineKeyboardMarkup->addRow(...$month);
                $month = [];
            }
        }
        if (count($month)) {
            $inlineKeyboardMarkup->addRow(...$month);
        }
        $inlineKeyboardMarkup->addRow(InlineKeyboardButton::make(text: 'На початок', callback_data: 0));
        $bot->sendMessage(
            text: 'Оберіть місяць:',
            reply_markup: $inlineKeyboardMarkup,
        );

        $this->next('chooseDay');
    }

    public function chooseDay(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || $bot->callbackQuery()->data == "0" || !str_contains($bot->callbackQuery()->data, 'month_')) {
            $this->chooseCar($bot);

            return;
        }

        $this->month = str_replace('month_', '', $bot->callbackQuery()->data);
        $bot->sendMessage(
            text: 'Місяць ' . $this->month
        );
        $current = ScheduleCarService::createNewDate();

        if ($this->month === $current->format('m')) {
            $currentDay = (int)$current->format('d');
        } else {
            $currentDay = 1;
        }

        $current->setDate((int)$current->format('Y'), (int)$this->month, $currentDay);

        $last = (clone $current)->modify('last day of');
        $lastDay = (int)$last->format('d');

        $inlineKeyboardMarkup = InlineKeyboardMarkup::make();
        $days = [];
        for ($i = $currentDay; $i <= $lastDay; $i++) {
            if ($i == $currentDay) {
                $format = $current->format('M-d');
            } else {
                $format = $current->modify('+1 day')->format('M-d');
            }
            $days[] = InlineKeyboardButton::make(
                text: $format, callback_data: 'day_' . $current->format('d')
            );
            if (count($days) == 4) {
                $inlineKeyboardMarkup->addRow(...$days);
                $days = [];
            }
        }
        if (count($days)) {
            $inlineKeyboardMarkup->addRow(...$days);
        }
        $inlineKeyboardMarkup->addRow(InlineKeyboardButton::make(text: 'На початок', callback_data: 0));
        $bot->sendMessage(
            text: 'Оберіть день',
            reply_markup: $inlineKeyboardMarkup,
        );

        $this->next('chooseTimeSet');
    }

    public function chooseTimeSet(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || $bot->callbackQuery()->data == "0" || !str_contains($bot->callbackQuery()->data, 'day_')) {
            $this->chooseCar($bot);

            return;
        }

        $this->day = str_replace('day_', '', $bot->callbackQuery()->data);

        $bot->sendMessage(
            text: 'День ' . $this->day
        );

        $current = ScheduleCarService::createNewDate();

        $scheduledSets = $this->scheduleCarService->getExistSet(
            (int)$this->carId,
            (int)$current->format('Y'),
            (int)$this->month,
            (int)$this->day,
        );

        $chosenDate = ScheduleCarService::createNewDate();
        $chosenDate->setDate((int)$current->format('Y'), (int)$this->month, (int)$this->day);
        $chosenDate->setTime(0, 0);
        if ($current->format('Y-m-d') == $chosenDate->format('Y-m-d')) {
            $currentHour = (int)$current->format('H');
            $currentHour += 1;
            $chosenDate->setTime($currentHour, 0);
            $last = 24;
        } else {
            $currentHour = 0;
            $last = 24;
        }

        $availableHours = [];
        for ($i = $currentHour; $i < $last; $i++) {
            if (array_key_exists($i, $scheduledSets)) {
                continue;
            }
            $availableHours[] = $i;
        }

        $inlineKeyboardMarkup = InlineKeyboardMarkup::make();
        $hours = [];
        foreach ($availableHours as $availableHourItem) {
            $format = $chosenDate->setTime($availableHourItem, 0)->format('D/H-i');
            $hours[] = InlineKeyboardButton::make(
                text: $format, callback_data: 'hour_' . $chosenDate->format('H')
            );

            if (count($hours) == 3) {
                $inlineKeyboardMarkup->addRow(...$hours);
                $hours = [];
            }
        }

        if (count($hours)) {
            $inlineKeyboardMarkup->addRow(...$hours);
        }

        $inlineKeyboardMarkup->addRow(InlineKeyboardButton::make(text: 'На початок', callback_data: 0));

        foreach ($scheduledSets as $set) {
            $key = strlen($set->getHour()) == 1 ? '0' . $set->getHour() : $set->getHour();
            if ($set->getTelegramUserId()->getTelegramId() == $this->telegramUserService->getCurrentUser()->getTelegramId()) {
                $scheduledByCurrentUserDate = $set->getScheduledDateTime();

                $bot->sendMessage(
                    text: sprintf('Відмінити: машина №%s, час: %s', $set->getCar()->getCarNumber(), $scheduledByCurrentUserDate->format('Y-m-d/H-i')),
                    parse_mode: ParseMode::HTML,
                    reply_markup: InlineKeyboardMarkup::make()
                        ->addRow(
                            InlineKeyboardButton::make(
                                'Відмінити', callback_data: 'decline_' . 'decline_' . $key
                            ),
                        )
                );
            } else {
                $bot->sendMessage(
                    text: sprintf('година %s:00, заброньована: %s', $key, $set->getTelegramUserId()->concatNameInfo()),
                );
            }
        }

        if (count($availableHours)) {
            $bot->sendMessage(
                text: 'Оберіть час нового бронювання:',
                reply_markup: $inlineKeyboardMarkup,
            );
        } else {
            $bot->sendMessage(
                text: 'Нажадь немає доступних бронювань. Оберіть іншу дату.',
                reply_markup: $inlineKeyboardMarkup,
            );
        }


        $this->next('scheduleDate');
    }

    public function scheduleDate(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()
            || $bot->callbackQuery()->data == "0"
            || (!str_contains($bot->callbackQuery()->data, 'hour_') && !str_contains($bot->callbackQuery()->data, 'decline_'))
        ) {
            $this->chooseCar($bot);

            return;
        }

        $chosenHour = $bot->callbackQuery()->data;
        if (str_contains($chosenHour, 'decline_')) {
            $this->hour = str_replace('decline_', '', $chosenHour);

            $current = ScheduleCarService::createNewDate();
            $dateTime = ScheduleCarService::createNewDate();
            $dateTime->setDate((int)$current->format('Y'), (int)$this->month, (int)$this->day);
            $dateTime->setTime((int)$this->hour, 0);
            $bot->sendMessage(
                text: 'Дата: ' . $dateTime->format('Y/m/d H:i'),
            );

            $bot->sendMessage(
                text: 'Видалити бронювання? Натисніть *Підтверджую*',
                parse_mode: ParseMode::MARKDOWN,
                reply_markup: InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make(text: 'Підтверджую', callback_data: 1),
                    InlineKeyboardButton::make(text: 'На початок', callback_data: 0),
                )
            );

            $this->next('removeScheduled');
        } else {
            $this->hour = str_replace('hour_', '', $chosenHour);

            $current = ScheduleCarService::createNewDate();
            $dateTime = ScheduleCarService::createNewDate();
            $dateTime->setDate((int)$current->format('Y'), (int)$this->month, (int)$this->day);
            $dateTime->setTime((int)$this->hour, 0);
            $bot->sendMessage(
                text: sprintf('Машина %s. Дата: %s', $this->getCarInfo(), $dateTime->format('Y/m/d H:i')),
            );
            $bot->sendMessage(
                text: 'Якщо згодні натисніть *Підтверджую*',
                parse_mode: ParseMode::MARKDOWN,
                reply_markup: InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make(text: 'Підтверджую', callback_data: 1),
                    InlineKeyboardButton::make(text: 'На початок', callback_data: 0),
                )
            );

            $this->next('approveDate');
        }
    }

    public function removeScheduled(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || $bot->callbackQuery()->data != "1") {
            $this->chooseCar($bot);

            return;
        }
        $current = ScheduleCarService::createNewDate();
        $scheduledSets = $this->scheduleCarService->getExistSet(
            $this->carId,
            (int)$current->format('Y'),
            (int)$this->month,
            (int)$this->day,
            (int)$this->hour,
            $this->telegramUserService->getCurrentUser()
        );
        if (!$scheduledSets) {
            $bot->sendMessage(
                text: '<b>Не знайшло ваше бронювання.</b>',
                parse_mode: ParseMode::HTML
            );

            $this->chooseCar($bot);

            return;
        }
        $scheduledSet = array_shift($scheduledSets);
        $this->em->remove($scheduledSet);
        $this->em->flush();

        $bot->sendMessage(
            text: '<b>Ваше бронювання видалено.</b>',
            parse_mode: ParseMode::HTML
        );

        $this->end();
    }

    public function approveDate(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || $bot->callbackQuery()->data != "1") {
            $this->chooseCar($bot);

            return;
        }

        $scheduledSet = (new ScheduledSet())
            ->setTelegramUserId($this->telegramUserService->getCurrentUser())
            ->setYear((int)(ScheduleCarService::createNewDate())->format('Y'))
            ->setMonth((int)$this->month)
            ->setDay((int)$this->day)
            ->setHour((int)$this->hour)
            ->setCar($this->carRepository->find($this->carId));
        $scheduledSet->setScheduledAt($scheduledSet->getScheduledDateTime());

        $this->em->persist($scheduledSet);

        $lists = $this->validator->validate($scheduledSet);
        if (count($lists)) {
            foreach ($lists as $list) {
                $bot->sendMessage(
                    text: '<b>' . $list->getMessage() . '</b>',
                    parse_mode: ParseMode::HTML
                );
                $this->chooseCar($bot);

                return;
            }
            $bot->sendMessage(
                text: '<b>Сталась помилка.</b>',
                parse_mode: ParseMode::HTML
            );
            $this->chooseCar($bot);

            return;
        }
        $this->em->flush();

        $bot->sendMessage(
            text: '<b>Вітаємо</b>, заброньовано, водій отримає сповіщення. Можете переглянути в Ваших бронюваннях. <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>',
            parse_mode: ParseMode::HTML
        );

        foreach ($scheduledSet->getCar()->getCarDriver() as $carDriver) {
            /** @var Message $message */
            $message = $bot->sendMessage(
                text: sprintf(
                    'Вас забронював працівник %s на дату %s',
                    $this->telegramUserService->getCurrentUser()->concatNameInfo(),
                    $scheduledSet->getScheduledAt()->format('Y/m/d H:i:s')
                ),
                chat_id: $carDriver->getDriver()->getChatId(),
                parse_mode: ParseMode::HTML
            );
        }

        $this->end();
    }

    /**
     * @return string|null
     */
    public function getCarInfo(): ?string
    {
        return $this->carRepository->find($this->carId)->getCarInfo();
    }
}