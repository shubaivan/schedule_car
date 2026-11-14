<?php

namespace App\Tasks\Controller;

use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Service\TaskPolicy;
use App\Tasks\Service\TaskPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Опис одного файлу для сторінки /crm/files/:id — переглядач без модалки,
 * на який можна дати посилання (з пошуку, сповіщення, «поділитися»).
 * Права ті самі, що й на сам файл: бачиш задачу — бачиш і файл.
 */
class TaskFileMetaController extends TasksController
{
    public function __construct(private TaskPolicy $policy, private TaskPresenter $presenter)
    {
    }

    #[Route('/api/tasks/files/{id}', name: 'api_tasks_file_meta', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function meta(TaskAttachment $attachment): JsonResponse
    {
        $me = $this->me();
        $task = $attachment->getTask();

        if ($attachment->getMessage()->getDeletedAt() !== null || ! $this->policy->canView($me, $task)) {
            throw $this->createNotFoundException();
        }

        return $this->json([
            'file' => $this->presenter->attachment($attachment),
            'task' => ['id' => $task->getId(), 'number' => $task->number(), 'title' => $task->getTitle()],
        ]);
    }
}
