<?php

namespace App\Tasks\Controller;

use App\Entity\TelegramUser;
use App\Supply\Entity\Department;
use App\Supply\Repository\DepartmentRepository;
use App\Tasks\Exception\TaskException;
use App\Tasks\Service\StaffDirectory;
use App\Tasks\Service\TaskPresenter;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * «Співробітники»: усі, відділи, структура компанії, кошик.
 * Дивитися може кожен (структура — для всіх), змінювати — адміністратор.
 */
#[Route('/api/staff')]
class StaffApiController extends TasksController
{
    public function __construct(
        private StaffDirectory $staff,
        private TaskPresenter $presenter,
        private DepartmentRepository $departments,
    ) {
    }

    #[Route('', name: 'api_staff_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $me = $this->me();
        $trash = $request->query->getBoolean('trash');

        if ($trash && ! $me->managesStaff()) {
            throw $this->createAccessDeniedException();
        }

        return $this->json([
            'items' => array_map(fn (TelegramUser $u) => $this->row($u, $me), $this->staff->list($trash)),
        ]);
    }

    #[Route('', name: 'api_staff_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $me = $this->admin();

        try {
            $user = $this->staff->create($this->payload($request), $me);
        } catch (TaskException $e) {
            return $this->error($e);
        }

        return $this->json($this->row($user, $me), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_staff_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(TelegramUser $user, Request $request): JsonResponse
    {
        $me = $this->admin();

        try {
            $this->staff->update($user, $this->payload($request), $me);
        } catch (TaskException $e) {
            return $this->error($e);
        } catch (AccessDeniedException $e) {
            return $this->error($e->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->row($user, $me));
    }

    #[Route('/{id}/trash', name: 'api_staff_trash', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function trash(TelegramUser $user): JsonResponse
    {
        $me = $this->admin();

        try {
            $this->staff->trash($user, $me);
        } catch (TaskException $e) {
            return $this->error($e);
        } catch (AccessDeniedException $e) {
            return $this->error($e->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->row($user, $me));
    }

    #[Route('/{id}/restore', name: 'api_staff_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function restore(TelegramUser $user): JsonResponse
    {
        $me = $this->admin();

        try {
            $this->staff->restore($user, $me);
        } catch (AccessDeniedException $e) {
            return $this->error($e->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->row($user, $me));
    }

    /** Відділи з людьми — і для вкладки «Відділи», і для «Структури компанії». */
    #[Route('/departments', name: 'api_staff_departments', methods: ['GET'])]
    public function departments(): JsonResponse
    {
        $me = $this->me();
        $people = $this->staff->list(false);

        return $this->json(['items' => array_map(fn (Department $d) => [
            'id' => $d->getId(),
            'name' => $d->getName(),
            'people' => array_values(array_map(
                fn (TelegramUser $u) => $this->presenter->person($u),
                array_filter($people, static fn (TelegramUser $u) => $u->getDepartment()?->getId() === $d->getId()),
            )),
        ], $this->departments->findActive()), 'unassigned' => array_values(array_map(
            fn (TelegramUser $u) => $this->presenter->person($u),
            array_filter($people, static fn (TelegramUser $u) => $u->getDepartment() === null),
        )), 'canEdit' => $me->managesStaff()]);
    }

    #[Route('/departments', name: 'api_staff_department_create', methods: ['POST'])]
    public function createDepartment(Request $request): JsonResponse
    {
        $this->admin();

        try {
            $department = $this->staff->department((string) ($this->payload($request)['name'] ?? ''));
        } catch (TaskException $e) {
            return $this->error($e);
        }

        return $this->json(['id' => $department->getId(), 'name' => $department->getName(), 'people' => []], Response::HTTP_CREATED);
    }

    private function admin(): TelegramUser
    {
        $me = $this->me();

        if (! $me->managesStaff()) {
            throw $this->createAccessDeniedException('Співробітників веде адміністратор.');
        }

        return $me;
    }

    /** @return array<string, mixed> */
    private function row(TelegramUser $user, TelegramUser $me): array
    {
        $date = static fn (?DateTimeInterface $d) => $d?->format(DateTimeInterface::ATOM);

        return [
            ...$this->presenter->person($user),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            // Контакти бачать усі колеги: це внутрішній довідник заводу.
            'phone' => $user->getPhoneNumber(),
            'email' => $user->getEmail(),
            'hasPassword' => $user->getPassword() !== null,
            'telegram' => ! str_starts_with((string) $user->getTelegramId(), 'staff-'),
            'lastSeenAt' => $date($user->getLastSeenAt()),
            'archivedAt' => $date($user->getArchivedAt()),
            'archivedBy' => $user->getArchivedBy()?->shortName(),
            'me' => $user->getId() === $me->getId(),
            // Пароль, контакти й роль цієї людини поточний адмін змінити не може (суперадмін / колега-адмін).
            'protected' => ! StaffDirectory::canChange($me, $user, ['password']),
        ];
    }
}
