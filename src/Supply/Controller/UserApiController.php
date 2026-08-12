<?php

namespace App\Supply\Controller;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Supply\Enum\SupplyRole;
use App\Supply\Repository\DepartmentRepository;
use App\Supply\Service\RequestPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Люди: хто в якому підрозділі і з якою роллю. Змінювати може лише адміністратор. */
#[Route('/api/supply/users')]
class UserApiController extends AbstractController
{
    public function __construct(
        private TelegramUserRepository $users,
        private RequestPresenter $presenter,
    ) {
    }

    #[Route('', name: 'api_supply_users', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $users = $this->users->findBy([], ['first_name' => 'ASC']);

        return $this->json(['items' => array_map($this->presenter->user(...), $users)]);
    }

    #[IsGranted('ROLE_SUPPLY_ADMIN')]
    #[Route('/{id}', name: 'api_supply_user_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(
        TelegramUser $user,
        Request $request,
        DepartmentRepository $departments,
        EntityManagerInterface $em,
    ): JsonResponse {
        $payload = json_decode((string)$request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];

        if (array_key_exists('role', $payload)) {
            $role = SupplyRole::tryFrom((string)$payload['role']);

            if ($role === null) {
                return $this->json(['error' => 'Невідома роль.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $user->setSupplyRole($role);
        }

        if (array_key_exists('departmentId', $payload)) {
            $department = $payload['departmentId'] !== null
                ? $departments->find((int)$payload['departmentId'])
                : null;

            if ($payload['departmentId'] !== null && $department === null) {
                return $this->json(['error' => 'Підрозділ не знайдено.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $user->setDepartment($department);
        }

        $em->flush();

        return $this->json($this->presenter->user($user));
    }
}
