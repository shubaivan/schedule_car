<?php

namespace App\Plan\Audit;

use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Журнал аудиту «Плану» — лише суперадмінам (PLAN_AUDIT_VIEW = is_superadmin
 * і людина активна). Решта отримує 403 на кожен маршрут, включно з CSV.
 *
 *     GET /api/plan/audit?actor=&group[]=&type[]=&kind=&subject=&from=&to=&ip=&q=&before=|after=&limit=
 *     GET /api/plan/audit/meta       — довідники фільтрів
 *     GET /api/plan/audit/{id}       — одна подія з payload і сусідами
 *     GET /api/plan/audit.csv?…      — ті самі фільтри, ≤ 50 000 рядків
 */
#[Route('/api/plan/audit')]
#[IsGranted('PLAN_AUDIT_VIEW')]
final class AuditApiController extends AbstractController
{
    public const int CSV_MAX = 50000;

    public function __construct(
        private readonly AuditLog $log,
        private readonly AuditPresenter $presenter,
    ) {
    }

    #[Route('', name: 'api_plan_audit', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        try {
            $filter = AuditFilter::fromQuery($request->query);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $page = $this->log->page($filter);
        $total = $this->log->count($filter);

        return $this->json([
            'items' => $this->presenter->rows($page['items']),
            'older' => $page['older'],
            'newer' => $page['newer'],
            'total' => min($total, AuditLog::countCap()),
            'totalCapped' => $total > AuditLog::countCap(),
        ]);
    }

    #[Route('/meta', name: 'api_plan_audit_meta', methods: ['GET'])]
    public function meta(): JsonResponse
    {
        return $this->json([
            'groups' => AuditCatalog::groupsForUi(),
            'subjects' => AuditCatalog::subjectsForUi(),
            'actors' => $this->log->actors(),
            'csvMax' => self::CSV_MAX,
            'retention' => ['domainMonths' => AuditCatalog::DOMAIN_MONTHS, 'accessMonths' => AuditCatalog::ACCESS_MONTHS],
        ]);
    }

    #[Route('/{id}', name: 'api_plan_audit_event', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function event(int $id): JsonResponse
    {
        $row = $this->log->find($id);

        if ($row === null) {
            return $this->json(['error' => 'Подію не знайдено (могла бути видалена за строком зберігання).'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->presenter->detail($row, $this->log->around($row)));
    }

    /** Excel відкриває як є: UTF-8 з BOM, крапка з комою. */
    #[Route('.csv', name: 'api_plan_audit_csv', methods: ['GET'])]
    public function csv(Request $request): Response
    {
        try {
            $filter = AuditFilter::fromQuery($request->query);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // Довгий експорт не має тримати сесію (інші вкладки чекали б на її блокування).
        if ($request->hasSession()) {
            $request->getSession()->save();
        }

        $response = new StreamedResponse(function () use ($filter): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\u{FEFF}");
            fputcsv($out, AuditPresenter::csvHeader(), ';', '"', '');
            $batch = [];

            foreach ($this->log->export($filter, self::CSV_MAX) as $row) {
                $batch[] = $row;

                if (count($batch) === 500) {
                    $this->writeBatch($out, $batch);
                    $batch = [];
                }
            }

            $this->writeBatch($out, $batch);
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition('attachment', sprintf('audit-%s.csv', date('Y-m-d-His'))));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @param resource $out
     * @param list<array{id: int, type: string, subject_type: string, subject_id: int, actor_id: ?int, occurred_at: string, payload: string, ip: ?string, channel: string}> $batch
     */
    private function writeBatch($out, array $batch): void
    {
        if ($batch === []) {
            return;
        }

        $names = $this->presenter->names($batch);

        foreach ($batch as $row) {
            fputcsv($out, array_map(self::cell(...), $this->presenter->csv($row, $names)), ';', '"', '');
        }

        flush();
    }

    /** Формула з даних (=, +, -, @ на початку) у Excel не виконується. */
    private static function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}
