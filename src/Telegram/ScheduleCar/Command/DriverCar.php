<?php

namespace App\Telegram\ScheduleCar\Command;

use App\Repository\CarDriverRepository;
use App\Repository\ScheduledSetRepository;
use App\Service\TelegramUserService;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

class DriverCar extends Command
{
    protected ?string $step = 'chooseCar';

    public function __construct(
        private TelegramUserService $telegramUserService,
        private CarDriverRepository $carDriverRepository,
        private ScheduledSetRepository $scheduledSetRepository,
        $callable = null, ?string $command = null)
    {
        parent::__construct($callable, $command);
    }

    public function handle(Nutgram $bot): void
    {
        $telegramUser = $this->telegramUserService->getCurrentUser();
        $carDriver = $this->carDriverRepository->findOneByDriver($telegramUser);
        if (!$carDriver) {
            $bot->sendMessage(
                text: '<b>Ви не водій</b>',
                parse_mode: ParseMode::HTML
            );

            return;
        }

        $scheduled = $this->scheduledSetRepository->getByCar($carDriver->getCar());
        if (!$scheduled) {
            $bot->sendMessage(
                text: '<b>Бронювання відсутні</b>',
                parse_mode: ParseMode::HTML
            );

            return;
        }

        foreach ($scheduled as $set) {
            $key = strlen($set->getHour()) == 1 ? '0' . $set->getHour() : $set->getHour();

            $bot->sendMessage(
                text: sprintf('година <b>%s:00</b>, заброньована: <b>%s</b>', $key, $set->getTelegramUserId()->concatNameInfo()),
                parse_mode: ParseMode::HTML
            );
        }
    }
}