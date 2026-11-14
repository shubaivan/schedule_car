<?php

namespace App\Tasks\Controller;

use App\Plan\Event\FileDownloaded;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Share\FileType;
use App\Plan\Subject\SubjectRef;
use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Service\AttachmentStorage;
use App\Tasks\Service\PreviewGenerator;
use App\Tasks\Service\TaskPolicy;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Віддача файлів і превʼю. Файли поза public/, тож кожен запит проходить
 * перевірку: людина бачить задачу — бачить і її файли.
 */
#[Route('/api/tasks/files/{id}', requirements: ['id' => '\d+'])]
class TaskFileController extends TasksController
{
    /** Типи, які безпечно показати в браузері «як є». HTML/SVG — лише скачуванням. */
    private const INLINE_SAFE = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'text/plain'];

    public function __construct(
        private AttachmentStorage $storage,
        private PreviewGenerator $previews,
        private TaskPolicy $policy,
        private PlanEventRecorder $events,
    ) {
    }

    #[Route('/thumb', name: 'api_tasks_file_thumb', methods: ['GET'])]
    public function thumb(TaskAttachment $attachment): Response
    {
        return $this->image($this->allowed($attachment), $this->storage->thumb($attachment));
    }

    #[Route('/view', name: 'api_tasks_file_view', methods: ['GET'])]
    public function view(TaskAttachment $attachment): Response
    {
        return $this->image($this->allowed($attachment), $this->storage->view($attachment));
    }

    #[Route('/page/{page}', name: 'api_tasks_file_page', methods: ['GET'], requirements: ['page' => '\d+'])]
    public function page(TaskAttachment $attachment, int $page): Response
    {
        $path = $this->previews->page($this->allowed($attachment), $page);

        if ($path === null) {
            throw $this->createNotFoundException();
        }

        return $this->image($attachment, $path);
    }

    #[Route('/inline', name: 'api_tasks_file_inline', methods: ['GET'])]
    public function inline(TaskAttachment $attachment): Response
    {
        $this->allowed($attachment);
        $safe = in_array($attachment->getMime(), self::INLINE_SAFE, true);

        return $this->send($attachment, $safe ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }

    #[Route('/download', name: 'api_tasks_file_download', methods: ['GET'])]
    public function download(TaskAttachment $attachment, Request $request): Response
    {
        $response = $this->send($this->allowed($attachment), ResponseHeaderBag::DISPOSITION_ATTACHMENT);

        // Скачування — у журнал аудиту (лише аудит, не Хроніка).
        $this->events->record((new FileDownloaded(
            SubjectRef::task((int) $attachment->getTask()->getId()),
            $this->me()->getId(),
            (int) $attachment->getId(),
            FileType::Task->value,
            $attachment->getOriginalName(),
        ))->via('web', $request->getClientIp()));

        return $response;
    }

    private function allowed(TaskAttachment $attachment): TaskAttachment
    {
        $me = $this->me();

        if ($attachment->getMessage()->getDeletedAt() !== null || ! $this->policy->canView($me, $attachment->getTask())) {
            throw $this->createNotFoundException();
        }

        return $attachment;
    }

    private function image(TaskAttachment $attachment, string $path): Response
    {
        if (! is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path, 200, ['Content-Type' => 'image/jpeg']);
        // Превʼю не змінюються — браузер тримає їх у кеші, чат не блимає.
        $response->setPrivate();
        $response->setMaxAge(86400 * 30);
        $response->setEtag($attachment->getSha256() . '-' . basename($path));

        return $response;
    }

    private function send(TaskAttachment $attachment, string $disposition): Response
    {
        $path = $this->storage->original($attachment);

        if (! is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path, 200, ['Content-Type' => $attachment->getMime()]);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition($disposition, $attachment->getOriginalName(), 'file-' . $attachment->getId() . '.' . (AttachmentStorage::extension($attachment->getOriginalName()) ?: 'bin'));
        $response->setPrivate();

        return $response;
    }
}
