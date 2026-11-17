<?php

namespace App\Plan\Zvyazok;

use App\Entity\TelegramUser;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API «Звʼязку» (ZvyazokService). Доступ — ZvyazokAccess (до погодження лише повний
 * доступ); без нього — 404, як і чужий чат. Записи — лише JSON-запитом самого Плану.
 *
 *   GET    /api/plan/zvyazok/chats?q=&archived=1    ліва колонка
 *   GET    /api/plan/zvyazok/unread                  бейдж у меню
 *   GET    /api/plan/zvyazok/people                  кого можна додати
 *   POST   /api/plan/zvyazok/chats                   {kind: direct, userId} | {kind: group, title, memberIds}
 *   GET    /api/plan/zvyazok/chats/{id}              шапка й учасники
 *   PATCH  /api/plan/zvyazok/chats/{id}              {title?, add?, remove?} — власник групи
 *   POST   /api/plan/zvyazok/chats/{id}/flag         {pinned|archived: bool} — особисто
 *   POST   /api/plan/zvyazok/chats/{id}/leave
 *   GET    /api/plan/zvyazok/chats/{id}/messages?before=|after=
 *   POST   /api/plan/zvyazok/chats/{id}/messages     {text, replyTo?, mentions?} JSON або multipart з files[] (до 10, по 20 МБ)
 *   GET    /api/plan/zvyazok/files/{id}/thumb|inline|download   файл — лише учасникам чату
 *   POST   /api/plan/zvyazok/chats/{id}/read         {messageId}
 *   PATCH  /api/plan/zvyazok/messages/{id}           {text} — автор
 *   DELETE /api/plan/zvyazok/messages/{id}           для всіх — автор
 *   GET    /api/plan/zvyazok/messages/{id}/history   попередні версії
 */
#[Route('/api/plan/zvyazok')]
final class ZvyazokApiController extends AbstractController
{
    /** Що безпечно показати в браузері «як є»; решта — лише скачуванням. */
    private const array INLINE_SAFE = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly ZvyazokService $service,
        private readonly ZvyazokAccess $access,
        private readonly ZvyazokFiles $files,
    ) {
    }

    #[Route('/chats', name: 'api_plan_zv_chats', methods: ['GET'])]
    public function chats(Request $request): JsonResponse
    {
        return $this->run($request, fn (TelegramUser $me) => [
            'items' => $this->service->chats($me, mb_substr((string) $request->query->get('q', ''), 0, 100), $request->query->getBoolean('archived')),
            'unread' => $this->service->unreadTotal($me),
        ]);
    }

    #[Route('/unread', name: 'api_plan_zv_unread', methods: ['GET'])]
    public function unread(Request $request): JsonResponse
    {
        return $this->run($request, fn (TelegramUser $me) => ['unread' => $this->service->unreadTotal($me)]);
    }

    #[Route('/people', name: 'api_plan_zv_people', methods: ['GET'])]
    public function people(Request $request): JsonResponse
    {
        return $this->run($request, fn (TelegramUser $me) => ['items' => $this->service->directory($me)]);
    }

    #[Route('/chats', name: 'api_plan_zv_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->run($request, function (TelegramUser $me, array $data): array {
            $id = ($data['kind'] ?? '') === 'direct'
                ? $this->service->direct($me, (int) ($data['userId'] ?? 0))
                : $this->service->group($me, (string) ($data['title'] ?? ''), self::ids($data['memberIds'] ?? []));

            return $this->service->chat($me, $id);
        }, write: true, created: true);
    }

    #[Route('/chats/{id}', name: 'api_plan_zv_chat', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function chat(Request $request, int $id): JsonResponse
    {
        return $this->run($request, fn (TelegramUser $me) => $this->service->chat($me, $id));
    }

    #[Route('/chats/{id}', name: 'api_plan_zv_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->run($request, function (TelegramUser $me, array $data) use ($id): array {
            $this->service->updateGroup($me, $id, isset($data['title']) ? (string) $data['title'] : null, self::ids($data['add'] ?? []), self::ids($data['remove'] ?? []));

            return $this->service->chat($me, $id);
        }, write: true);
    }

    #[Route('/chats/{id}/flag', name: 'api_plan_zv_flag', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function flag(Request $request, int $id): JsonResponse
    {
        return $this->run($request, function (TelegramUser $me, array $data) use ($id): array {
            foreach (['pinned', 'archived'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $this->service->setFlag($me, $id, $flag, (bool) $data[$flag]);
                }
            }

            return $this->service->chat($me, $id);
        }, write: true);
    }

    #[Route('/chats/{id}/leave', name: 'api_plan_zv_leave', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function leave(Request $request, int $id): JsonResponse
    {
        return $this->run($request, function (TelegramUser $me) use ($id): array {
            $this->service->leave($me, $id);

            return ['left' => true];
        }, write: true);
    }

    #[Route('/chats/{id}/messages', name: 'api_plan_zv_messages', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function messages(Request $request, int $id): JsonResponse
    {
        $before = $request->query->get('before');
        $after = $request->query->get('after');

        return $this->run($request, fn (TelegramUser $me) => $this->service->messages(
            $me,
            $id,
            is_numeric($before) ? (int) $before : null,
            is_numeric($after) ? (int) $after : null,
        ));
    }

    #[Route('/chats/{id}/messages', name: 'api_plan_zv_post', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function post(Request $request, int $id): JsonResponse
    {
        // З файлами — multipart (FormData): text, replyTo, mentions[] і files[].
        if ($request->getContentTypeFormat() === 'form') {
            return $this->run($request, function (TelegramUser $me) use ($request, $id): array {
                $files = array_values(array_filter(
                    (array) ($request->files->all()['files'] ?? []),
                    static fn (mixed $f) => $f instanceof UploadedFile,
                ));
                $replyTo = $request->request->get('replyTo');

                return $this->service->post(
                    $me,
                    $id,
                    (string) $request->request->get('text', ''),
                    is_numeric($replyTo) ? (int) $replyTo : null,
                    self::ids($request->request->all()['mentions'] ?? []),
                    $files,
                );
            }, multipart: true, created: true);
        }

        return $this->run($request, fn (TelegramUser $me, array $data) => $this->service->post(
            $me,
            $id,
            (string) ($data['text'] ?? ''),
            isset($data['replyTo']) && is_numeric($data['replyTo']) ? (int) $data['replyTo'] : null,
            self::ids($data['mentions'] ?? []),
        ), write: true, created: true);
    }

    #[Route('/chats/{id}/read', name: 'api_plan_zv_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function read(Request $request, int $id): JsonResponse
    {
        return $this->run($request, fn (TelegramUser $me, array $data) => ['lastReadId' => $this->service->read($me, $id, (int) ($data['messageId'] ?? 0))], write: true);
    }

    #[Route('/messages/{id}', name: 'api_plan_zv_edit', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function edit(Request $request, int $id): JsonResponse
    {
        return $this->run($request, fn (TelegramUser $me, array $data) => $this->service->edit($me, $id, (string) ($data['text'] ?? '')), write: true);
    }

    #[Route('/messages/{id}', name: 'api_plan_zv_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        return $this->run($request, function (TelegramUser $me) use ($id): array {
            $this->service->delete($me, $id);

            return ['deleted' => true];
        }, write: true);
    }

    #[Route('/messages/{id}/history', name: 'api_plan_zv_history', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function history(Request $request, int $id): JsonResponse
    {
        return $this->run($request, fn (TelegramUser $me) => ['items' => $this->service->history($me, $id)]);
    }

    #[Route('/files/{id}/{variant}', name: 'api_plan_zv_file', requirements: ['id' => '\d+', 'variant' => 'thumb|inline|download'], methods: ['GET'])]
    public function attachment(int $id, string $variant): Response
    {
        $me = $this->getUser();

        if (! $me instanceof TelegramUser || ! $this->access->can($me)) {
            return new JsonResponse(['error' => 'Не знайдено.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $file = $this->service->file($me, $id);
        } catch (ZvyazokException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->status());
        }

        if ($variant === 'thumb') {
            $path = $this->files->thumbPath($id);
            $mime = 'image/jpeg';
        } else {
            $path = $this->files->path((string) $file['path']);
            $mime = (string) $file['mime'];
        }

        if (! is_file($path)) {
            return new JsonResponse(['error' => 'Файл не знайдено.'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path, 200, ['Content-Type' => $mime]);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPrivate();

        if ($variant === 'thumb') {
            $response->setMaxAge(86400 * 30);
            $response->setEtag((string) $file['sha256'] . '-thumb');

            return $response;
        }

        // У браузері «як є» — лише фото й PDF; офісні документи — скачуванням.
        $inline = $variant === 'inline' && in_array($mime, self::INLINE_SAFE, true);
        $name = (string) $file['original_name'];
        $response->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $name,
            'file-' . $id . '.' . (pathinfo($name, PATHINFO_EXTENSION) ?: 'bin'),
        );

        return $response;
    }

    /** @param callable(TelegramUser, array<string, mixed>): array<string, mixed> $work */
    private function run(Request $request, callable $work, bool $write = false, bool $created = false, bool $multipart = false): JsonResponse
    {
        $me = $this->getUser();

        if (! $me instanceof TelegramUser || ! $this->access->can($me)) {
            return new JsonResponse(['error' => 'Не знайдено.'], Response::HTTP_NOT_FOUND);
        }

        $data = [];

        if ($multipart) {
            // Форма з файлами: чужий сайт не може додати X-Requested-With без preflight (CORS) —
            // це й захист від підробки запиту (як JSON для решти записів).
            if ($request->headers->get('X-Requested-With') !== 'XMLHttpRequest' || $request->headers->get('Sec-Fetch-Site', 'same-origin') !== 'same-origin') {
                return new JsonResponse(['error' => 'Очікується запит із самого Плану.'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            }
        } elseif ($write) {
            // Записи — лише JSON-запитом із самого Плану (HTML-форма чужого сайту так не може).
            if ($request->getContentTypeFormat() !== 'json' || $request->headers->get('Sec-Fetch-Site', 'same-origin') !== 'same-origin') {
                return new JsonResponse(['error' => 'Очікується JSON-запит із самого Плану.'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            }

            try {
                $decoded = json_decode($request->getContent() ?: '{}', true, 8, JSON_THROW_ON_ERROR);
                $data = is_array($decoded) ? $decoded : [];
            } catch (JsonException) {
                return new JsonResponse(['error' => 'Некоректний JSON.'], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            return new JsonResponse($work($me, $data), $created ? Response::HTTP_CREATED : Response::HTTP_OK);
        } catch (ZvyazokException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->status());
        }
    }

    /** @return list<int> */
    private static function ids(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter(array_map('intval', $value), static fn (int $id) => $id > 0)) : [];
    }
}
