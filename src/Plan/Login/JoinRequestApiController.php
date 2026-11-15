<?php

namespace App\Plan\Login;

use App\Entity\TelegramUser;
use App\Plan\Login\Entity\JoinRequest;
use App\Supply\Repository\DepartmentRepository;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Exception\TaskException;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Заявки на приєднання в Плані (/crm/admin/join) — лише суперадміну (PLAN_STAFF_JOIN).
 *
 *     GET  /api/plan/join-requests?status=pending|approved|rejected
 *     GET  /api/plan/join-requests/{id}
 *     POST /api/plan/join-requests/{id}/approve  {role, departmentId, firstName, lastName, position}
 *     POST /api/plan/join-requests/{id}/reject   {reason}
 */
#[Route('/api/plan/join-requests')]
#[IsGranted('PLAN_STAFF_JOIN')]
final class JoinRequestApiController extends AbstractController
{
    public function __construct(
        private readonly JoinRequests $joins,
        private readonly JoinNotifier $notifier,
        private readonly DepartmentRepository $departments,
    ) {
    }

    #[Route('', name: 'api_plan_join_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $status = JoinRequestStatus::tryFrom((string) $request->query->get('status'));

        return $this->json([
            'items' => array_map(self::row(...), $this->joins->list($status)),
            'pending' => $this->joins->pendingCount(),
            'roles' => array_map(static fn (WorkRole $r) => ['value' => $r->value, 'label' => $r->label()], WorkRole::cases()),
            'departments' => array_map(static fn ($d) => ['id' => $d->getId(), 'name' => $d->getName()], $this->departments->findActive()),
        ]);
    }

    #[Route('/{id}', name: 'api_plan_join_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $request = $this->joins->find($id) ?? throw $this->createNotFoundException();

        return $this->json(self::row($request));
    }

    #[Route('/{id}/approve', name: 'api_plan_join_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(int $id, Request $http): JsonResponse
    {
        $request = $this->joins->find($id) ?? throw $this->createNotFoundException();
        $me = $this->me();
        $data = self::payload($http);

        try {
            $this->joins->approve($request, $me, [
                'role' => (string) ($data['role'] ?? WorkRole::Production->value),
                'departmentId' => (int) ($data['departmentId'] ?? 0),
                'firstName' => (string) ($data['firstName'] ?? ''),
                'lastName' => (string) ($data['lastName'] ?? ''),
                'position' => (string) ($data['position'] ?? ''),
            ]);
        } catch (TaskException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        $this->notifier->decided($request, $me);

        return $this->json(self::row($request));
    }

    #[Route('/{id}/reject', name: 'api_plan_join_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(int $id, Request $http): JsonResponse
    {
        $request = $this->joins->find($id) ?? throw $this->createNotFoundException();
        $me = $this->me();

        try {
            $this->joins->reject($request, $me, trim((string) (self::payload($http)['reason'] ?? '')) ?: null);
        } catch (TaskException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        $this->notifier->decided($request, $me);

        return $this->json(self::row($request));
    }

    /** @return array<string, mixed> */
    private static function row(JoinRequest $r): array
    {
        return [
            'id' => $r->getId(),
            'name' => $r->name(),
            'firstName' => $r->getFirstName(),
            'lastName' => $r->getLastName(),
            'username' => $r->getUsername(),
            'phone' => JoinRequests::formatPhone($r->getPhone()),
            'status' => $r->getStatus()->value,
            'statusLabel' => $r->getStatus()->label(),
            'createdAt' => $r->getCreatedAt()->format(DATE_ATOM),
            'decidedAt' => $r->getDecidedAt()?->format(DATE_ATOM),
            'decidedBy' => $r->getDecidedBy()?->shortName(),
            'userId' => $r->getUser()?->getId(),
            'userRole' => $r->getUser()?->getWorkRole()->label(),
            'reason' => $r->getReason(),
        ];
    }

    /** @return array<string, mixed> */
    private static function payload(Request $request): array
    {
        try {
            $data = json_decode($request->getContent() ?: '{}', true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function me(): TelegramUser
    {
        $me = $this->getUser();

        if (! $me instanceof TelegramUser) {
            throw $this->createAccessDeniedException();
        }

        return $me;
    }
}
