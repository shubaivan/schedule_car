<?php

namespace App\Supply\Controller;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Exception\SupplyException;
use App\Supply\Service\PeopleDirectory;
use App\Supply\Service\RequestPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Люди: хто в якому підрозділі, з якою роллю і чи відкрито доступ.
 *
 * Веде список менеджер із постачання — він і так підтверджує реєстрації в
 * боті, тож тримати це за адміністратором означало б зупиняти роботу через
 * кожного нового робітника. Правила — у PeopleDirectory.
 */
#[IsGranted('ROLE_SUPPLY_MANAGER')]
#[Route('/api/supply/users')]
class UserApiController extends AbstractController
{
    public function __construct(
        private PeopleDirectory $people,
        private RequestPresenter $presenter,
    ) {
    }

    #[Route('', name: 'api_supply_users', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $people = $this->people->all($request->query->getBoolean('archived'));

        return $this->json(['items' => array_map($this->presenter->user(...), $people)]);
    }

    #[Route('/{id}', name: 'api_supply_user_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(TelegramUser $user, Request $request): JsonResponse
    {
        try {
            $this->people->update($user, $this->payload($request), $this->manager());
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->presenter->user($user));
    }

    /** Погодити чи відхилити доступ. Людині про рішення пише бот. */
    #[Route('/{id}/access', name: 'api_supply_user_access', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function access(TelegramUser $user, Request $request): JsonResponse
    {
        $status = AccessStatus::tryFrom((string) ($this->payload($request)['status'] ?? ''));

        if ($status === null || $status === AccessStatus::Pending) {
            return $this->error('Оберіть рішення: погодити чи відхилити.');
        }

        try {
            $this->people->decide($user, $status, $this->manager());
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->presenter->user($user));
    }

    #[Route('/{id}/restore', name: 'api_supply_user_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function restore(TelegramUser $user): JsonResponse
    {
        try {
            $this->people->restore($user, $this->manager());
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->presenter->user($user));
    }

    /**
     * Прибрати людину. Якщо на ній висить історія заявок, запис не зникає
     * назовсім, а йде в архів із закритим доступом — відповідь про це каже.
     */
    #[Route('/{id}', name: 'api_supply_user_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(TelegramUser $user): JsonResponse
    {
        $name = $user->displayName();

        try {
            $deleted = $this->people->remove($user, $this->manager());
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json([
            'ok' => true,
            'deleted' => $deleted,
            'message' => $deleted
                ? sprintf('%s видалено зі списку.', $name)
                : sprintf('%s прибрано зі списку — заявки цієї людини лишились в історії.', $name),
        ]);
    }

    private function manager(): TelegramUser
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
