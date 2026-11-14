<?php

namespace App\Warehouse\Controller\Api;

use App\Entity\TelegramUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * JSON API складу для «Плану» (/api/warehouse/*). Twig-склад /sklad живе поруч
 * без змін — тут ті самі сервіси (RecordMovement, WarehouseDirectory, DocumentStore,
 * ActivityLog), лише інший формат.
 *
 * Хто: PLAN_WAREHOUSE_VIEW (PlanAccess = WarehouseSection::canManage — менеджер,
 * адмін, директор, як і на /sklad); зміни — PLAN_WAREHOUSE_EDIT. Розділ вимкнено
 * (WAREHOUSE_ENABLED=0) — WarehouseGate віддає 404 ще до файрвола.
 *
 * #[IsGranted] — на КОЖНОМУ класі-нащадку: атрибут батьківського класу Symfony не успадковує.
 */
abstract class AbstractWarehouseApiController extends AbstractController
{
    protected function me(): TelegramUser
    {
        $user = $this->getUser();

        if (! $user instanceof TelegramUser) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }

    protected function error(string $message, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): JsonResponse
    {
        return $this->json(['error' => $message], $status);
    }
}
