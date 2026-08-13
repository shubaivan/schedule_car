<?php

namespace App\Supply\Telegram;

use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use App\Supply\Enum\AttachmentType;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\AttachFile;
use App\Supply\Service\RequestFormatter;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use Throwable;

/**
 * «📎 Накладна» — документ до заявки прямо з телефона.
 *
 * Накладну майже завжди фотографують на місці, тож шлях «сфотографував —
 * надіслав у чат» коротший за вхід у CRM. Файл лягає в те саме сховище, що й
 * завантажений у CRM, і тим же порядком їде в Google Drive.
 */
class AttachConversation extends Conversation
{
    protected ?string $step = 'ask';

    public ?int $requestId = null;

    public function __construct(
        private SupplyRequestRepository $repository,
        private TelegramUserService $telegramUserService,
        private AttachFile $attachFile,
        private RequestFormatter $formatter,
        private ChatScreen $screen,
        private RequestView $view,
    ) {
    }

    public function ask(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $data = (string) ($bot->callbackQuery()->data ?? '');
        $this->requestId = (int) substr($data, strlen(SupplyCallback::ATTACH_PREFIX));

        $request = $this->repository->find($this->requestId);

        if ($request === null) {
            $this->screen->render($bot, '⚠️ Заявку не знайдено.');
            $this->end();

            return;
        }

        $this->screen->render($bot, sprintf(
            "📎 Документ до заявки №%s\n%s — %s\n\n"
            . 'Надішліть фото накладної або файл (PDF, Word, Excel), не більше %d МБ.',
            $this->formatter->escape($request->getNumber()),
            $this->formatter->escape($request->getItem()),
            $request->getQuantityLabel(),
            AttachFile::MAX_SIZE / 1024 / 1024,
        ));

        $this->next('readFile');
    }

    public function readFile(Nutgram $bot): void
    {
        $message = $bot->message();
        $file = $this->fileFrom($message);

        if ($file === null) {
            $this->screen->render($bot, 'Надішліть саме фото або файл — текст сюди не підійде.');

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
            $contents = $this->download($bot, $file['id']);
        } catch (Throwable $e) {
            // Найчастіше це стеля Telegram у 20 МБ на завантаження ботом.
            $this->screen->render($bot, '⚠️ Не вдалось забрати файл із Telegram. Спробуйте ще раз.');
            $this->end();

            return;
        }

        try {
            ($this->attachFile)(
                $request,
                $user,
                $contents,
                $file['name'],
                $file['mime'],
                AttachmentType::Invoice,
            );

            // Повертаємо картку: у ній уже видно свіжий документ.
            $this->view->show($bot, $request, $user);
        } catch (SupplyException $e) {
            $this->screen->render($bot, '⚠️ ' . $this->formatter->escape($e->getMessage()));
        }

        $this->end();
    }

    /**
     * Фото Telegram віддає кількома розмірами — беремо останній, він найбільший.
     * Стиснуте фото приходить без імені й типу, тож підставляємо свої.
     *
     * @return array{id: string, name: string, mime: string}|null
     */
    private function fileFrom(?Message $message): ?array
    {
        if ($message === null) {
            return null;
        }

        $document = $message->document;

        if ($document !== null) {
            return [
                'id' => $document->file_id,
                'name' => $document->file_name ?: 'документ',
                'mime' => $document->mime_type ?: 'application/octet-stream',
            ];
        }

        $photos = $message->photo ?? [];

        if ($photos) {
            $photo = end($photos);

            return [
                'id' => $photo->file_id,
                'name' => 'накладна.jpg',
                'mime' => 'image/jpeg',
            ];
        }

        return null;
    }

    /** Nutgram уміє лише «завантажити у файл», тож кладемо в tmp і одразу прибираємо. */
    private function download(Nutgram $bot, string $fileId): string
    {
        $file = $bot->getFile($fileId);

        if ($file === null) {
            throw new SupplyException('Telegram не віддав файл.');
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'supply-');

        try {
            if ($bot->downloadFile($file, $path) !== true) {
                throw new SupplyException('Не вдалось завантажити файл.');
            }

            return (string) file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
