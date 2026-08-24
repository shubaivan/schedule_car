<?php

namespace App\Supply\Controller;

use App\Entity\TelegramUser;
use App\Supply\Entity\Department;
use App\Supply\Exception\SupplyException;
use App\Supply\Service\DepartmentDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Довідник підрозділів у CRM.
 *
 * Веде його менеджер із постачання: він же розбирає заявки й бачить, який цех
 * з'явився, а який більше нічого не замовляє.
 */
#[IsGranted('ROLE_SUPPLY_MANAGER')]
#[Route('/api/supply/departments')]
class DepartmentApiController extends AbstractController
{
    public function __construct(private DepartmentDirectory $directory)
    {
    }

    #[Route('', name: 'api_supply_departments', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['items' => array_map($this->item(...), $this->directory->all())]);
    }

    #[Route('', name: 'api_supply_department_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $department = $this->directory->create(
                (string) ($this->payload($request)['name'] ?? ''),
                $this->user(),
            );
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->item($department), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_supply_department_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(Department $department, Request $request): JsonResponse
    {
        $payload = $this->payload($request);

        try {
            if (array_key_exists('name', $payload)) {
                $this->directory->rename($department, (string) $payload['name'], $this->user());
            }

            if (array_key_exists('active', $payload)) {
                $this->directory->setActive($department, (bool) $payload['active']);
            }
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->item($department));
    }

    /**
     * Прибрати підрозділ. Якщо на ньому висять заявки чи люди, він не зникає
     * назовсім, а лише йде зі списку вибору — відповідь про це й повідомляє.
     */
    #[Route('/{id}', name: 'api_supply_department_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(Department $department): JsonResponse
    {
        $name = $department->getName();
        $deleted = $this->directory->remove($department, $this->user());

        return $this->json([
            'ok' => true,
            'deleted' => $deleted,
            'message' => $deleted
                ? sprintf('Підрозділ «%s» видалено.', $name)
                : sprintf('«%s» прибрано зі списку вибору — на ньому висить історія заявок.', $name),
        ]);
    }

    private function item(Department $department): array
    {
        $usage = $this->directory->usage($department);

        return [
            'id' => $department->getId(),
            'name' => $department->getName(),
            'active' => $department->isActive(),
            'requests' => $usage['requests'],
            'people' => $usage['people'],
        ];
    }

    private function user(): TelegramUser
    {
        /** @var TelegramUser $user */
        $user = $this->getUser();

        return $user;
    }

    private function payload(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    private function error(string $message): JsonResponse
    {
        return $this->json(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
