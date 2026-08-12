<?php

namespace App\Telegram\Access;

use App\Enum\AccessStatus;
use App\Repository\TelegramUserRepository;
use App\Service\AccessNotifier;
use App\Service\AccessService;
use App\Service\TelegramUserService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/** Менеджер натиснув «Підтвердити» або «Відхилити» під заявкою на реєстрацію. */
class AccessDecision
{
    public function __construct(
        private TelegramUserRepository $users,
        private TelegramUserService $telegramUserService,
        private AccessService $accessService,
        private AccessNotifier $notifier,
    ) {
    }

    public function __invoke(Nutgram $bot, string $id): void
    {
        $bot->answerCallbackQuery();

        $manager = $this->telegramUserService->getCurrentUser();
        $candidate = $this->users->find((int) $id);

        if ($manager === null || ! $manager->getSupplyRole()->canManage()) {
            $bot->sendMessage(text: '⚠️ Підтверджувати реєстрації може лише менеджер.');

            return;
        }

        if ($candidate === null) {
            $bot->sendMessage(text: '⚠️ Користувача не знайдено.');

            return;
        }

        $status = str_starts_with((string) $bot->callbackQuery()?->data, AccessCallback::APPROVE_PREFIX)
            ? AccessStatus::Approved
            : AccessStatus::Rejected;

        $this->accessService->decide($candidate, $status, $manager);

        $bot->sendMessage(
            text: $this->notifier->decisionSummary($candidate, $status),
            parse_mode: ParseMode::HTML,
        );
    }
}
