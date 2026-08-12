<?php

namespace App\Supply\Telegram;

use App\Service\TelegramUserService;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\RequestFormatter;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

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
    ) {
    }

    public function askReason(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $data = (string)($bot->callbackQuery()?->data ?? '');
        $this->requestId = (int)substr($data, strlen(SupplyCallback::REJECT_PREFIX));

        $request = $this->repository->find($this->requestId);

        if ($request === null) {
            $bot->sendMessage(text: '⚠️ Заявку не знайдено.');
            $this->end();

            return;
        }

        $bot->sendMessage(
            text: sprintf(
                "⛔ Відхилення заявки №%s\n\nНапишіть причину — вона піде заявнику.",
                $this->formatter->escape($request->getNumber()),
            ),
            parse_mode: ParseMode::HTML,
        );

        $this->next('readReason');
    }

    public function readReason(Nutgram $bot): void
    {
        $reason = trim((string)$bot->message()?->text);

        if ($reason === '') {
            $bot->sendMessage(text: 'Напишіть причину текстом.');

            return;
        }

        $request = $this->repository->find((int)$this->requestId);
        $user = $this->telegramUserService->getCurrentUser();

        if ($request === null || $user === null) {
            $bot->sendMessage(text: '⚠️ Заявку не знайдено.');
            $this->end();

            return;
        }

        try {
            ($this->changeStatus)($request, SupplyStatus::Rejected, $user, $reason);
            $bot->sendMessage(
                text: sprintf('✅ Заявку №%s відхилено, заявника сповіщено.', $this->formatter->escape($request->getNumber())),
                parse_mode: ParseMode::HTML,
            );
        } catch (SupplyException $e) {
            $bot->sendMessage(text: '⚠️ ' . $this->formatter->escape($e->getMessage()));
        }

        $this->end();
    }
}
