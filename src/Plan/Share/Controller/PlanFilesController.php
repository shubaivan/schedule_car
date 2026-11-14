<?php

namespace App\Plan\Share\Controller;

use App\Entity\TelegramUser;
use App\Plan\Share\Entity\ShareLink;
use App\Plan\Share\FileType;
use App\Plan\Share\PlanFile;
use App\Plan\Share\PlanFiles;
use App\Plan\Share\ShareException;
use App\Plan\Share\ShareService;
use App\Plan\Subject\SubjectType;
use App\Repository\TelegramUserRepository;
use App\Tasks\Service\TaskPresenter;
use App\Tasks\Service\TasksSection;
use DateTimeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Файл «Плану» будь-якого модуля: опис для читалки (/crm/files/…), скачування з
 * журналом, «поділитися» з людьми і тимчасові посилання.
 *
 * Файла немає або доступу немає — однаково 404: чужі id не підтверджуємо.
 */
#[Route('/api/plan', requirements: ['type' => 'task|supply|wh', 'id' => '\d+'])]
class PlanFilesController extends AbstractController
{
    /** Типи, які безпечно показати в браузері «як є» (HTML/SVG — лише скачуванням). */
    public const array INLINE_SAFE = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly PlanFiles $files,
        private readonly ShareService $shares,
        private readonly TelegramUserRepository $users,
        private readonly TaskPresenter $people,
        private readonly TasksSection $plan,
    ) {
    }

    #[Route('/files/{type}/{id}', name: 'api_plan_file', methods: ['GET'])]
    public function meta(string $type, int $id): JsonResponse
    {
        $me = $this->me();
        $file = $this->planFile($type, $id, $me);
        $subject = $file->subject;

        return $this->json([
            'file' => $this->files->present($file),
            'subject' => [
                'type' => $subject->type->value,
                'id' => $subject->id,
                'label' => $file->subjectLabel,
                // Куди веде «до задачі / заявки» у фронті (склад — свій модуль S3).
                'route' => match ($subject->type) {
                    SubjectType::Task => ['name' => 'tasks-board', 'params' => ['id' => (string) $subject->id]],
                    SubjectType::Supply => ['name' => 'supply-board', 'params' => ['id' => (string) $subject->id]],
                    default => null,
                },
            ],
            'drive' => ['state' => $file->driveState(), 'url' => $file->driveUrl()],
            'share' => ['ttlDays' => ShareService::TTL_DAYS],
        ]);
    }

    #[Route('/files/{type}/{id}/download', name: 'api_plan_file_download', methods: ['GET'])]
    public function download(string $type, int $id): Response
    {
        // Скачування в журнал аудиту пише FileDownloadAudit (за маршрутом, з 10-хв. зведенням повторів).
        return self::send($this->files, $this->planFile($type, $id, $this->me()), ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }

    #[Route('/files/{type}/{id}/inline', name: 'api_plan_file_inline', methods: ['GET'])]
    public function inline(string $type, int $id): Response
    {
        $file = $this->planFile($type, $id, $this->me());
        $safe = in_array($file->mime, self::INLINE_SAFE, true);
        $response = self::send($this->files, $file, $safe ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT);
        // Фото, відкрите напряму, — у пісочниці. PDF не чіпаємо: Chrome не показує PDF
        // під CSP sandbox, а його вбудований переглядач і так ізольований.
        if (str_starts_with($file->mime, 'image/')) {
            $response->headers->set('Content-Security-Policy', "sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
        }

        return $response;
    }

    /** Кому можна надіслати: співробітники з позначкою, чи є в них доступ до файлу. */
    #[Route('/files/{type}/{id}/recipients', name: 'api_plan_file_recipients', methods: ['GET'])]
    public function recipients(string $type, int $id, Request $request): JsonResponse
    {
        $me = $this->me();
        $file = $this->planFile($type, $id, $me);
        $q = mb_strtolower(trim((string) $request->query->get('q', '')));
        $out = [];

        foreach ($this->users->findPeople() as $user) {
            if ($user->getId() === $me->getId() || ! $user->isApproved()) {
                continue;
            }

            if ($q !== '' && ! str_contains(mb_strtolower($user->displayName()), $q)) {
                continue;
            }

            $out[] = [...(array) $this->people->person($user), 'canView' => $this->files->canView($user, $file)];
        }

        // Ті, кому можна, — вгорі; обрізаємо вже після сортування, щоб їх не загубити.
        usort($out, static fn (array $a, array $b) => [! $a['canView'], $a['name']] <=> [! $b['canView'], $b['name']]);

        return $this->json(['people' => array_slice($out, 0, 100)]);
    }

    #[Route('/files/{type}/{id}/share', name: 'api_plan_file_share', methods: ['POST'])]
    public function share(string $type, int $id, Request $request): JsonResponse
    {
        $me = $this->me();
        $file = $this->planFile($type, $id, $me);
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];

        try {
            $recipients = $this->shares->shareWithUsers(
                $file,
                $me,
                array_values(array_map('intval', array_filter((array) ($body['userIds'] ?? []), 'is_numeric'))),
                is_string($body['note'] ?? null) ? $body['note'] : null,
            );
        } catch (ShareException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['shared' => array_map(static fn (TelegramUser $u) => $u->shortName(), $recipients)], Response::HTTP_CREATED);
    }

    #[Route('/files/{type}/{id}/links', name: 'api_plan_file_links', methods: ['GET'])]
    public function links(string $type, int $id): JsonResponse
    {
        $me = $this->me();
        $file = $this->planFile($type, $id, $me);

        return $this->json(['links' => array_map(fn (ShareLink $l) => $this->link($l, $me, $file), $this->shares->activeLinks($file, $me))]);
    }

    #[Route('/files/{type}/{id}/links', name: 'api_plan_file_link_create', methods: ['POST'])]
    public function createLink(string $type, int $id): JsonResponse
    {
        $me = $this->me();
        $file = $this->planFile($type, $id, $me);

        try {
            [$link, $token] = $this->shares->createLink($file, $me);
        } catch (ShareException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Токен — лише в цій відповіді; у базі його немає (тільки хеш).
        return $this->json([
            ...$this->link($link, $me, $file),
            'url' => $this->shares->publicUrl($token),
        ], Response::HTTP_CREATED);
    }

    #[Route('/share-links/{id}/revoke', name: 'api_plan_share_link_revoke', methods: ['POST'])]
    public function revoke(int $id): JsonResponse
    {
        $me = $this->me();
        $link = $this->shares->linkById($id);
        $file = $link !== null ? $this->files->find($link->getFileType(), $link->getFileId()) : null;

        if ($link === null || $file === null || ! $this->shares->canRevoke($me, $link, $file)) {
            throw $this->createNotFoundException();
        }

        $this->shares->revoke($link, $me, $file);

        return $this->json($this->link($link, $me, $file));
    }

    /** Віддати вміст файлу (спільне з /s/{token}). */
    public static function send(PlanFiles $files, PlanFile $file, string $disposition): Response
    {
        $path = $files->localPath($file);

        if ($path !== null) {
            if (! is_file($path)) {
                throw new NotFoundHttpException();
            }

            $response = new BinaryFileResponse($path, 200, ['Content-Type' => $file->mime]);
        } else {
            try {
                $stream = $files->readStream($file);
            } catch (Throwable) {
                throw new NotFoundHttpException();
            }

            // Шматками, а не весь файл у пам'ять дитини FPM (пул — 4 дитини; nginx буферизує відповідь).
            $response = new StreamedResponse(static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            }, 200, ['Content-Type' => $file->mime]);

            if ($file->size > 0) {
                $response->headers->set('Content-Length', (string) $file->size);
            }
        }

        $ext = strtolower((string) pathinfo($file->name, PATHINFO_EXTENSION));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            $disposition,
            $file->name,
            'file-' . $file->id . (preg_match('/^[a-z0-9]{1,8}$/', $ext) === 1 ? '.' . $ext : ''),
        ));
        $response->setPrivate();

        return $response;
    }

    /** @return array<string, mixed> */
    private function link(ShareLink $l, TelegramUser $me, PlanFile $file): array
    {
        return [
            'id' => $l->getId(),
            'createdBy' => $l->getCreatedBy()->shortName(),
            'mine' => $l->getCreatedBy()->getId() === $me->getId(),
            'createdAt' => $l->getCreatedAt()->format(DateTimeInterface::ATOM),
            'expiresAt' => $l->getExpiresAt()->format(DateTimeInterface::ATOM),
            'revokedAt' => $l->getRevokedAt()?->format(DateTimeInterface::ATOM),
            'downloads' => $l->getDownloads(),
            'canRevoke' => $this->shares->canRevoke($me, $l, $file),
        ];
    }

    private function planFile(string $type, int $id, TelegramUser $me): PlanFile
    {
        $file = $this->files->find(FileType::from($type), $id);

        if ($file === null || ! $this->files->canView($me, $file)) {
            throw $this->createNotFoundException();
        }

        return $file;
    }

    private function me(): TelegramUser
    {
        $user = $this->getUser();

        // Файли й «поділитися» — частина «Плану»; на стендах заявок (TASKS_ENABLED=0) їх немає.
        if (! $this->plan->enabled()) {
            throw $this->createNotFoundException();
        }

        if (! $user instanceof TelegramUser || $user->isArchived() || ! $user->isApproved()) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
