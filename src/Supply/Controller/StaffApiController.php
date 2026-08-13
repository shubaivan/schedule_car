<?php

namespace App\Supply\Controller;

use App\Entity\TelegramUser;
use App\Supply\Entity\StaffPhone;
use App\Supply\Enum\SupplyRole;
use App\Supply\Exception\SupplyException;
use App\Supply\Service\RequestPresenter;
use App\Supply\Service\StaffDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Довідник телефонів: кому яку роль видати при реєстрації.
 *
 * Ведуть його ті, хто й так підтверджує людей у боті, — менеджери. Роль
 * адміністратора з довідника може призначити лише адміністратор, це перевіряє
 * StaffDirectory.
 */
#[IsGranted('ROLE_SUPPLY_MANAGER')]
#[Route('/api/supply/staff')]
class StaffApiController extends AbstractController
{
    public function __construct(
        private StaffDirectory $directory,
        private RequestPresenter $presenter,
    ) {
    }

    #[Route('', name: 'api_supply_staff', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['items' => array_map($this->item(...), $this->directory->all())]);
    }

    #[Route('', name: 'api_supply_staff_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $role = SupplyRole::tryFrom((string) ($payload['role'] ?? ''));

        if ($role === null) {
            return $this->error('Оберіть роль зі списку.');
        }

        try {
            $entry = $this->directory->save(
                (string) ($payload['phone'] ?? ''),
                $role,
                $this->text($payload, 'name'),
                $this->text($payload, 'note'),
                $this->user(),
            );
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->item($entry), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_supply_staff_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(StaffPhone $staffPhone): JsonResponse
    {
        try {
            $this->directory->remove($staffPhone, $this->user());
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json(['ok' => true]);
    }

    private function item(StaffPhone $entry): array
    {
        return [
            'id' => $entry->getId(),
            'phone' => $entry->getPhone(),
            'name' => $entry->getName(),
            'role' => $entry->getRole()->value,
            'roleLabel' => $entry->getRole()->label(),
            'note' => $entry->getNote(),
            // Порожнє — людина ще не реєструвалась у боті, роль чекає на неї.
            'appliedTo' => $entry->getAppliedTo() !== null ? $this->presenter->user($entry->getAppliedTo()) : null,
            'appliedAt' => $entry->getAppliedAt()?->format(DATE_ATOM),
        ];
    }

    private function text(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
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
