<?php

namespace App\Supply\Telegram;

use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\RequestFormatter;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/** Відхилення заявки: спершу питаємо причину — без неї заявник не зрозуміє, що робити. */
class RejectConversation extends Conversation
{
    protected ?string $step = 'askReason';

    public ?int $requestId = null;

    public function __construct(
        private SupplyRequestRepository $repository,
        private TelegramUserService $telegramUserService,
        private ChangeStatus $changeStatus,
        private RequestFormatter $formatter,
        private ChatScreen $screen,
        private RequestView $view,
    ) {
    }

    public function askReason(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $data = (string)($bot->callbackQuery()?->data ?? '');
        $this->requestId = (int)substr($data, strlen(SupplyCallback::REJECT_PREFIX));

        $request = $this->repository->find($this->requestId);

        if ($request === null) {
            $this->screen->render($bot, '⚠️ Заявку не знайдено.');
            $this->end();

            return;
        }

        // Питання займає місце картки — інакше з її кнопок стартує друге відхилення.
        $this->screen->render($bot, sprintf(
            "⛔ Відхилення заявки №%s\n\nНапишіть причину — вона піде заявнику.",
            $this->formatter->escape($request->getNumber()),
        ));

        $this->next('readReason');
    }

    public function readReason(Nutgram $bot): void
    {
        $reason = trim((string)$bot->message()?->text);

        if ($reason === '') {
            $this->screen->render($bot, 'Напишіть причину текстом.');

            return;
        }

        $request = $this->repository->find((int)$this->requestId);
        $user = $this->telegramUserService->getCurrentUser();

        if ($request === null || $user === null) {
            $this->screen->render($bot, '⚠️ Заявку не знайдено.');
            $this->end();

            return;
        }

        try {
            ($this->changeStatus)($request, SupplyStatus::Rejected, $user, $reason);
            $this->view->show($bot, $request, $user->getSupplyRole()->canManage());
        } catch (SupplyException $e) {
            $this->screen->render($bot, '⚠️ ' . $this->formatter->escape($e->getMessage()));
        }

        $this->end();
    }
}
