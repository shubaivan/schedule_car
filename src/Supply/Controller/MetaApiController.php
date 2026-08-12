<?php

namespace App\Supply\Controller;

use App\Entity\TelegramUser;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Repository\DepartmentRepository;
use App\Supply\Service\RequestPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Хто я + усі довідники одним запитом на старті CRM. */
#[Route('/api')]
class MetaApiController extends AbstractController
{
    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(RequestPresenter $presenter): JsonResponse
    {
        /** @var TelegramUser $user */
        $user = $this->getUser();

        return $this->json($presenter->user($user));
    }

    #[Route('/supply/meta', name: 'api_supply_meta', methods: ['GET'])]
    public function meta(DepartmentRepository $departments): JsonResponse
    {
        return $this->json([
            'statuses' => array_map(
                static fn(SupplyStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'emoji' => $status->emoji(),
                    'final' => $status->isFinal(),
                ],
                SupplyStatus::cases(),
            ),
            'units' => array_map(
                static fn(Unit $unit) => ['value' => $unit->value, 'label' => $unit->label()],
                Unit::cases(),
            ),
            'departments' => array_map(
                static fn($department) => ['id' => $department->getId(), 'name' => $department->getName()],
                $departments->findActive(),
            ),
        ]);
    }
}
