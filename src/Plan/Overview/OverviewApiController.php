<?php

namespace App\Plan\Overview;

use App\Tasks\Controller\TasksController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** «Огляд» v2: усі віджети екрана директора одним запитом (DirectorOverview). */
#[Route('/api/plan/overview')]
final class OverviewApiController extends TasksController
{
    #[Route('', name: 'api_plan_overview', methods: ['GET'])]
    public function overview(DirectorOverview $overview): JsonResponse
    {
        $response = $this->json($overview->build($this->me()));

        // Кирилиця без \uXXXX — удвічі менше байтів; кеш браузера не потрібен (живі цифри).
        return $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE)
            ->setPrivate()->setMaxAge(0);
    }
}
