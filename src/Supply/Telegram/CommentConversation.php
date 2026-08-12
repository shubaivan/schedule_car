<?php

namespace App\Supply\Telegram;

use App\Service\TelegramUserService;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\AddComment;
use App\Supply\Service\RequestFormatter;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/** Коментар до заявки з бота: і заявник, і менеджер пишуть в одну стрічку. */
class CommentConversation extends Conversation
{
    protected ?string $step = 'askText';

    public ?int $requestId = null;

    public function __construct(
        private SupplyRequestRepository $repository,
        private TelegramUserService $telegramUserService,
        private AddComment $addComment,
        private RequestFormatter $formatter,
    ) {
    }

    public function askText(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $data = (string)($bot->callbackQuery()?->data ?? '');
        $this->requestId = (int)substr($data, strlen(SupplyCallback::COMMENT_PREFIX));

        $request = $this->repository->find($this->requestId);

        if ($request === null) {
            $bot->sendMessage(text: '⚠️ Заявку не знайдено.');
            $this->end();

            return;
        }

        $bot->sendMessage(
            text: sprintf(
                "💬 Коментар до заявки №%s\n%s — %s\n\nНапишіть текст:",
                $this->formatter->escape($request->getNumber()),
                $this->formatter->escape($request->getItem()),
                $request->getQuantityLabel(),
            ),
            parse_mode: ParseMode::HTML,
        );

        $this->next('readText');
    }

    public function readText(Nutgram $bot): void
    {
        $text = trim((string)$bot->message()?->text);

        if ($text === '') {
            $bot->sendMessage(text: 'Напишіть коментар текстом.');

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
            ($this->addComment)($request, $user, $text);
            $bot->sendMessage(text: '✅ Коментар додано.');
        } catch (SupplyException $e) {
            $bot->sendMessage(text: '⚠️ ' . $this->formatter->escape($e->getMessage()));
        }

        $this->end();
    }
}
