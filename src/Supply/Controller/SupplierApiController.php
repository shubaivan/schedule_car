<?php

namespace App\Supply\Controller;

use App\Entity\TelegramUser;
use App\Supply\Entity\Supplier;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplierRepository;
use App\Supply\Service\RequestPresenter;
use App\Supply\Service\SupplierDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Довідник постачальників — робоче місце менеджера.
 *
 * Заявки бачать усі, а от вести список постачальників і правити його —
 * справа того, хто закуповує.
 */
#[IsGranted('ROLE_SUPPLY_MANAGER')]
#[Route('/api/supply/suppliers')]
class SupplierApiController extends AbstractController
{
    public function __construct(
        private SupplierRepository $repository,
        private SupplierDirectory $directory,
        private RequestPresenter $presenter,
    ) {
    }

    #[Route('', name: 'api_supply_suppliers', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        // За замовчуванням показуємо всіх, зокрема прихованих: це екран довідника,
        // а не вибір у формі. Підказка при заповненні передасть active=1.
        $onlyActive = $request->query->getBoolean('active');

        $suppliers = $query === '' && ! $onlyActive
            ? $this->repository->findBy([], ['name' => 'ASC'])
            : $this->repository->search($query, 50, $onlyActive);

        return $this->json(['items' => array_map($this->presenter->supplier(...), $suppliers)]);
    }

    #[Route('', name: 'api_supply_supplier_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->payload($request);

        /** @var TelegramUser $user */
        $user = $this->getUser();

        try {
            $supplier = $this->directory->create((string) ($payload['name'] ?? ''), $user, $payload);
        } catch (SupplyException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->presenter->supplier($supplier), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_supply_supplier_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(Supplier $supplier, Request $request): JsonResponse
    {
        try {
            $this->directory->update($supplier, $this->payload($request));
        } catch (SupplyException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->presenter->supplier($supplier));
    }

    private function payload(Request $request): array
    {
        $payload = json_decode((string) $request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }
}
