<?php

namespace App\Plan\Access;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Exception\TaskException;
use App\Tasks\Service\StaffDirectory;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * «Ролі й доступ» (Олександр 17.11): хто яку роль має, що кожна роль може, суперадміни, історія.
 * Лише адміністратор, системний адміністратор і суперадмін; іншим — 404 (сторінки ніби немає).
 * Записи — лише JSON-запитом із самого «Плану».
 *
 *   GET  /api/plan/roles                     усе для сторінки
 *   POST /api/plan/roles/permission          {role, permission, allowed}
 *   POST /api/plan/roles/people/{id}         {role} — через StaffDirectory (ті самі запобіжники й аудит)
 *   POST /api/plan/roles/superadmin          {userId, grant} — лише суперадмін
 */
#[Route('/api/plan/roles')]
final class RolesApiController extends AbstractController
{
    public function __construct(
        private readonly RolePermissions $permissions,
        private readonly StaffDirectory $staff,
        private readonly TelegramUserRepository $users,
    ) {
    }

    #[Route('', name: 'api_plan_roles', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->run(null, fn (TelegramUser $me) => $this->page($me));
    }

    #[Route('/permission', name: 'api_plan_roles_permission', methods: ['POST'])]
    public function permission(Request $request): JsonResponse
    {
        return $this->run($request, function (TelegramUser $me, array $data): array {
            $role = WorkRole::tryFrom((string) ($data['role'] ?? ''));
            $permission = RolePermission::tryFrom((string) ($data['permission'] ?? ''));

            if ($role === null || $permission === null || ! is_bool($data['allowed'] ?? null)) {
                throw new TaskException('Невідома роль чи право.');
            }

            $this->permissions->set($me, $role, $permission, $data['allowed']);

            return $this->page($me);
        });
    }

    #[Route('/people/{id}', name: 'api_plan_roles_person', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function person(Request $request, int $id): JsonResponse
    {
        return $this->run($request, function (TelegramUser $me, array $data) use ($id): array {
            $user = $this->users->find($id);
            $role = WorkRole::tryFrom((string) ($data['role'] ?? ''));

            if ($user === null || $user->isArchived() || $role === null) {
                throw new TaskException('Людину чи роль не знайдено.');
            }

            $this->staff->update($user, ['role' => $role->value], $me);

            return $this->page($me);
        });
    }

    #[Route('/superadmin', name: 'api_plan_roles_superadmin', methods: ['POST'])]
    public function superadmin(Request $request): JsonResponse
    {
        return $this->run($request, function (TelegramUser $me, array $data): array {
            $user = $this->users->find((int) ($data['userId'] ?? 0));

            if ($user === null || ! is_bool($data['grant'] ?? null)) {
                throw new TaskException('Людину не знайдено.');
            }

            $this->permissions->superadmin($me, $user, $data['grant']);

            return $this->page($me);
        });
    }

    /** @return array<string, mixed> */
    private function page(TelegramUser $me): array
    {
        $people = array_values(array_filter(
            $this->users->findBy([], ['first_name' => 'ASC', 'last_name' => 'ASC']),
            static fn (TelegramUser $u) => ! $u->isArchived() && $u->isApproved(),
        ));

        return [
            'me' => ['id' => $me->getId(), 'superadmin' => $me->isSuperadmin(), 'role' => $me->getWorkRole()->value],
            ...$this->permissions->matrix($me),
            'people' => array_map(static fn (TelegramUser $u) => [
                'id' => $u->getId(),
                'name' => $u->shortName(),
                'position' => $u->getPosition(),
                'department' => $u->getDepartment()?->getName(),
                'role' => $u->getWorkRole()->value,
                'superadmin' => $u->isSuperadmin(),
                // Які ролі поточна людина може цій видати (порожньо — змінювати не може).
                'assignable' => array_values(array_map(
                    static fn (WorkRole $r) => $r->value,
                    array_filter(WorkRole::cases(), static fn (WorkRole $r) => StaffDirectory::canChange($me, $u, ['role'], null, $r)),
                )),
            ], $people),
            'history' => $this->permissions->history(),
        ];
    }

    /** @param callable(TelegramUser, array<string, mixed>): array<string, mixed> $work */
    private function run(?Request $request, callable $work): JsonResponse
    {
        $me = $this->getUser();

        if (! $me instanceof TelegramUser || $me->isArchived() || ! $me->isApproved() || ! $me->managesRoles()) {
            return new JsonResponse(['error' => 'Не знайдено.'], Response::HTTP_NOT_FOUND);
        }

        $data = [];

        if ($request !== null) {
            if ($request->getContentTypeFormat() !== 'json' || $request->headers->get('Sec-Fetch-Site', 'same-origin') !== 'same-origin') {
                return new JsonResponse(['error' => 'Очікується JSON-запит із самого Плану.'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            }

            try {
                $decoded = json_decode($request->getContent() ?: '{}', true, 8, JSON_THROW_ON_ERROR);
                $data = is_array($decoded) ? $decoded : [];
            } catch (JsonException) {
                return new JsonResponse(['error' => 'Некоректний JSON.'], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            return new JsonResponse($work($me, $data));
        } catch (AccessDeniedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (TaskException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
