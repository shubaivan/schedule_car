<?php

namespace App\Plan\Presence;

use App\Entity\TelegramUser;
use App\Tasks\Controller\TasksController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Хто зараз у Плані» для шапки.
 * POST — heartbeat з видимої вкладки (розділ) і одразу список; GET — лише список, присутність не пише
 * (спільний TasksController::me() як і скрізь раз на 5 хв оновлює «остання активність» людини).
 */
#[Route('/api/plan/presence')]
final class PresenceApiController extends TasksController
{
    public function __construct(private PresenceTracker $tracker)
    {
    }

    #[Route('', name: 'api_plan_presence', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->respond($this->viewer());
    }

    #[Route('', name: 'api_plan_presence_beat', methods: ['POST'])]
    public function beat(Request $request): JsonResponse
    {
        $viewer = $this->viewer();
        $this->tracker->touch($viewer, PresenceSection::fromClient($this->payload($request)['section'] ?? null));

        return $this->respond($viewer);
    }

    private function viewer(): TelegramUser
    {
        $me = $this->me();

        // Ще не затверджений (заявка на доступ) не бачить, хто в Плані, і сам не рахується.
        if (! PresenceTracker::counts($me)) {
            throw $this->createAccessDeniedException();
        }

        return $me;
    }

    private function respond(TelegramUser $viewer): JsonResponse
    {
        $people = $this->tracker->online($viewer);
        $response = $this->json([
            'count' => count($people),
            'people' => $people,
            'heartbeatSeconds' => PresenceTracker::HEARTBEAT_SECONDS,
            'activeSeconds' => PresenceTracker::ACTIVE_SECONDS,
        ]);

        return $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE)
            ->setPrivate()->setMaxAge(0);
    }
}
