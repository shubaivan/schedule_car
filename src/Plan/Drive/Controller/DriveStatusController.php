<?php

namespace App\Plan\Drive\Controller;

use App\Entity\TelegramUser;
use App\Plan\Drive\DriveClientFactory;
use App\Plan\Drive\DriveSync;
use App\Plan\Share\FileType;
use App\Plan\Share\ShareService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Сторінка «Диск» для суперадміна й адміністратора: скільки файлів на Диску,
 * у черзі, зі збоєм; зупинка через відкликаний токен / паузу квоти; «Повторити».
 * Іншим — 404 (саме існування сторінки не розкриваємо).
 */
#[Route('/api/plan/drive')]
class DriveStatusController extends AbstractController
{
    private const array STATES = ['pending', 'done', 'failed', 'skipped'];

    public function __construct(
        private readonly DriveSync $sync,
        private readonly DriveClientFactory $drives,
        private readonly Connection $db,
    ) {
    }

    #[Route('', name: 'api_plan_drive_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $this->admin();
        $state = (string) $request->query->get('state', 'failed');
        $state = in_array($state, self::STATES, true) ? $state : 'failed';

        $counts = [];
        $items = [];

        foreach (FileType::cases() as $type) {
            $counts[$type->value] = array_fill_keys(self::STATES, 0);

            foreach ($this->db->fetchAllAssociative(sprintf(
                "SELECT CASE WHEN drive_file_id IS NOT NULL THEN 'done' ELSE drive_state END AS s, COUNT(*) AS n FROM %s GROUP BY 1",
                $type->table(),
            )) as $row) {
                $counts[$type->value][(string) $row['s']] = (int) $row['n'];
            }

            $where = $state === 'done' ? 'drive_file_id IS NOT NULL' : 'drive_file_id IS NULL AND drive_state = :state';

            foreach ($this->db->fetchAllAssociative(
                sprintf('SELECT id, original_name, drive_state, drive_attempts, drive_error, drive_next_at, drive_url FROM %s WHERE %s ORDER BY id DESC LIMIT 100', $type->table(), $where),
                ['state' => $state],
            ) as $row) {
                $items[] = [
                    'type' => $type->value,
                    'id' => (int) $row['id'],
                    'name' => $row['original_name'],
                    'state' => $state === 'done' ? 'done' : $row['drive_state'],
                    'attempts' => (int) $row['drive_attempts'],
                    'error' => $row['drive_error'],
                    'nextAt' => $row['drive_next_at'],
                    'driveUrl' => $row['drive_url'],
                ];
            }
        }

        return $this->json([
            'layout' => $this->drives->isPlanLayout() ? 'plan' : 'stand',
            'configured' => $this->drives->isConfigured(),
            'fake' => $this->drives->isFake(),
            'status' => $this->sync->status(),
            'counts' => $counts,
            'state' => $state,
            'items' => $items,
            'maxAttempts' => DriveSync::MAX_ATTEMPTS,
        ]);
    }

    /** {type, id} — один файл; {all: true} — усі failed. */
    #[Route('/retry', name: 'api_plan_drive_retry', methods: ['POST'])]
    public function retry(Request $request): JsonResponse
    {
        $this->admin();
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];

        if (($body['all'] ?? false) === true) {
            return $this->json(['queued' => $this->sync->retryAllFailed()]);
        }

        $type = FileType::tryFrom((string) ($body['type'] ?? ''));
        $id = (int) ($body['id'] ?? 0);

        if ($type === null || $id < 1) {
            return $this->json(['error' => 'Невідомий файл.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['queued' => $this->sync->retry($type, $id) ? 1 : 0]);
    }

    /** Новий токен уже покладено / квота минула — зняти зупинку. */
    #[Route('/resume', name: 'api_plan_drive_resume', methods: ['POST'])]
    public function resume(): JsonResponse
    {
        $this->admin();
        $this->sync->resume();

        return $this->json(['status' => $this->sync->status()]);
    }

    private function admin(): TelegramUser
    {
        $user = $this->getUser();

        if (! $user instanceof TelegramUser || $user->isArchived() || ! $user->isApproved() || ! ShareService::isAdmin($user)) {
            throw $this->createNotFoundException();
        }

        return $user;
    }
}
