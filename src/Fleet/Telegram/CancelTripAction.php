<?php

namespace App\Fleet\Telegram;

use App\Fleet\Service\FleetNotifier;
use App\Repository\ScheduledSetRepository;
use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/** Зняти своє бронювання. Чуже не чіпаємо — домовляються між собою. */
class CancelTripAction
{
    public function __construct(
        private ScheduledSetRepository $sets,
        private TelegramUserService $telegramUserService,
        private FleetNotifier $notifier,
        private ChatScreen $screen,
        private MyTrips $myTrips,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $data = (string) ($bot->callbackQuery()->data ?? '');
        $id = (int) substr($data, strlen(FleetCallback::CANCEL_PREFIX));

        $set = $this->sets->find($id);
        $user = $this->telegramUserService->getCurrentUser();

        if ($set === null || $user === null) {
            $this->myTrips->show($bot);

            return;
        }

        if ($set->getTelegramUserId()->getId() !== $user->getId()) {
            $this->screen->render($bot, '⚠️ Це чуже бронювання — зняти його може лише той, хто його зробив.');

            return;
        }

        try {
            $this->notifier->cancelled($set);
        } catch (Throwable) {
            // Сповіщення не критичне.
        }

        $this->em->remove($set);
        $this->em->flush();

        $this->myTrips->show($bot);
    }
}
