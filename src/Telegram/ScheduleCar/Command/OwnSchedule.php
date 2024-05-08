<?php

namespace App\Telegram\ScheduleCar\Command;

use App\Service\ScheduleCarService;
use App\Service\TelegramUserService;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

class OwnSchedule extends Conversation
{
    protected ?string $step = 'own';

    public ?int $id;

    public function __construct(
        protected string $projectDir,
        private ScheduleCarService $scheduleCarService,
        private EntityManagerInterface $em,
        private TelegramUserService $telegramUserService
    ) {}

    public function own(Nutgram $bot)
    {
        $scheduledSets = $this->scheduleCarService->getOwn(
            $this->telegramUserService->getCurrentUser()
        );
        $availableDecline = [];
        if (!$scheduledSets) {
            $bot->sendMessage(
                text: '<b>Немає бронювань</b>',
                parse_mode: ParseMode::HTML
            );
            $this->end();

            return;
        }

        foreach ($scheduledSets as $carNumber=>$set) {
            $bot->sendMessage(
                text: sprintf('Машина №%s. Ваші бронювання:', $carNumber),
            );
            foreach ($set as $specificSet) {
                $bot->sendMessage(
                    text: sprintf('Машина №:<b>%s</b>, час:<b>%s</b>', $specificSet->getCar()->getCarInfo(), $specificSet->getScheduledDateTime()->format('Y/m/d H:i')),
                    parse_mode: ParseMode::HTML,
                    reply_markup: InlineKeyboardMarkup::make()
                        ->addRow(
                            InlineKeyboardButton::make('Відмінити', callback_data: 'decline_' . $specificSet->getId()),
                        )
                );
            }
        }

        $this->next('scheduleDate');
    }

    public function scheduleDate(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()
            || $bot->callbackQuery()->data == "0"
            || !str_contains($bot->callbackQuery()->data, 'decline_')
        ) {
            $this->own($bot);

            return;
        }
        $this->id = str_replace('decline_', '', $bot->callbackQuery()->data);
        $scheduledSet = $this->scheduleCarService->getById($this->id);
        if (!$scheduledSet) {
            $bot->sendMessage(
                text: '<b>Немає бронювань</b>',
                parse_mode: ParseMode::HTML
            );
            $this->end();
            return;
        }
        $bot->sendMessage(
            text: sprintf('Машина №%s - %s', $scheduledSet->getCar()->getCarInfo(), $scheduledSet->getScheduledDateTime()->format('Y/m/d H:i')),
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
    }

    public function removeScheduled(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()
            || $bot->callbackQuery()->data != "1"
        ) {
            $this->own($bot);

            return;
        }

        $scheduledSet = $this->scheduleCarService->getById($this->id);
        if (!$scheduledSet) {
            $bot->sendMessage(
                text: '<b>Немає бронювань</b>',
                parse_mode: ParseMode::HTML
            );
            $this->end();
        }

        $this->em->remove($scheduledSet);
        $this->em->flush();

        $bot->sendMessage(
            text: '<b>Видалено</b>',
            parse_mode: ParseMode::HTML
        );

        $this->id = null;

        foreach ($scheduledSet->getCar()->getCarDriver() as $carDriver) {
            /** @var Message $message */
            $message = $bot->sendMessage(
                text: sprintf(
                    'Бронь скасовано, працівник %s на дату %s',
                    $this->telegramUserService->getCurrentUser()->concatNameInfo(),
                    $scheduledSet->getScheduledAt()->format('Y/m/d H:i:s')
                ),
                chat_id: $carDriver->getDriver()->getChatId(),
                parse_mode: ParseMode::HTML
            );
        }

        $this->own($bot);
    }
}