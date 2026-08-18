<?php

namespace App\Supply\Telegram;

use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\AddComment;
use App\Supply\Service\RequestFormatter;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/** Коментар до заявки з бота: і заявник, і менеджер пишуть в одну стрічку. */
class CommentConversation extends Conversation
{
    use CancelsToRequest;

    protected ?string $step = 'askText';

    public ?int $requestId = null;

    public function __construct(
        private SupplyRequestRepository $repository,
        private TelegramUserService $telegramUserService,
        private AddComment $addComment,
        private RequestFormatter $formatter,
        private ChatScreen $screen,
        private RequestView $view,
    ) {
    }

    public function askText(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $data = (string) ($bot->callbackQuery()->data ?? '');
        $this->requestId = (int) substr($data, strlen(SupplyCallback::COMMENT_PREFIX));

        $request = $this->repository->find($this->requestId);

        if ($request === null) {
            $this->screen->render($bot, '⚠️ Заявку не знайдено.');
            $this->end();

            return;
        }

        // Питання займає місце картки: поки чекаємо текст, її кнопки не мають
        // працювати, інакше з них стартує друга така сама розмова.
        $this->screen->render($bot, sprintf(
            "💬 Коментар до заявки №%s\n%s — %s\n\nНапишіть текст:",
            $this->formatter->escape($request->getNumber()),
            $this->formatter->escape($request->getItem()),
            $request->getQuantityLabel(),
        ), $this->cancelKeyboard());

        $this->next('readText');
    }

    public function readText(Nutgram $bot): void
    {
        if ($this->cancelled($bot)) {
            return;
        }

        $text = trim((string) $bot->message()?->text);

        if ($text === '') {
            $this->screen->render($bot, 'Напишіть коментар текстом.', $this->cancelKeyboard());

            return;
        }

        $request = $this->repository->find((int) $this->requestId);
        $user = $this->telegramUserService->getCurrentUser();

        if ($request === null || $user === null) {
            $this->screen->render($bot, '⚠️ Заявку не знайдено.');
            $this->end();

            return;
        }

        try {
            ($this->addComment)($request, $user, $text);
            // Повертаємо картку — коментар уже видно в її хронології.
            $this->view->show($bot, $request, $user);
        } catch (SupplyException $e) {
            $this->screen->render($bot, '⚠️ ' . $this->formatter->escape($e->getMessage()));
        }

        $this->end();
    }
}
