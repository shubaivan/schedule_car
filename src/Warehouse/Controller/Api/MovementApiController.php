<?php

namespace App\Warehouse\Controller\Api;

use App\Warehouse\Api\MovementLoader;
use App\Warehouse\Api\MovementWriter;
use App\Warehouse\Api\ViewJournal;
use App\Warehouse\Api\WarehousePresenter;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Enum\MovementType;
use App\Warehouse\Exception\WarehouseException;
use App\Warehouse\Repository\WhActivityRepository;
use App\Warehouse\Service\DocumentStore;
use DateTime;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Рухи складу для «Плану»: список із фільтрами, рух повністю, новий рух. */
#[Route('/api/warehouse/movements')]
#[IsGranted('PLAN_WAREHOUSE_VIEW')]
class MovementApiController extends AbstractWarehouseApiController
{
    private const int LIMIT = 200;

    public function __construct(
        private readonly WarehousePresenter $presenter,
        private readonly DocumentStore $documents,
        private readonly WhActivityRepository $journal,
    ) {
    }

    /** ?type=shipment&site=4&item=7&q=накладна&from=2026-11-01&to=2026-11-14 */
    #[Route('', name: 'api_wh_movements', methods: ['GET'])]
    public function index(Request $request, MovementLoader $loader): JsonResponse
    {
        $q = $request->query;
        $qb = $loader->filter();

        if (($type = MovementType::tryFrom((string) $q->get('type', ''))) !== null) {
            $qb->andWhere('m.type = :type')->setParameter('type', $type);
        }

        if ($q->getInt('site') > 0) {
            $qb->andWhere('m.fromSite = :site OR m.toSite = :site')->setParameter('site', $q->getInt('site'));
        }

        if ($q->getInt('item') > 0) {
            $qb->andWhere('EXISTS (SELECT 1 FROM App\Warehouse\Entity\WhMovementLine li WHERE li.movement = m AND li.item = :item)')->setParameter('item', $q->getInt('item'));
        }

        $text = trim((string) $q->get('q', ''));

        if ($text !== '') {
            $qb->andWhere('LOWER(m.documentNumber) LIKE :q OR LOWER(m.note) LIKE :q OR LOWER(f.name) LIKE :q OR LOWER(t.name) LIKE :q OR LOWER(s.name) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($text) . '%');
        }

        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            $date = DateTime::createFromFormat('!Y-m-d', (string) $q->get($key, ''));

            if ($date !== false) {
                $qb->andWhere(sprintf('m.occurredAt %s :%s', $op, $key))->setParameter($key, $date);
            }
        }

        $movements = $loader->load($qb, self::LIMIT);

        return $this->json([
            'items' => array_map(fn (WhMovement $m) => $this->presenter->movementRow($m), $movements),
            'total' => count($movements),
            'limit' => self::LIMIT,
        ]);
    }

    #[Route('/{id}', name: 'api_wh_movement', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(WhMovement $movement): JsonResponse
    {
        return $this->json($this->presenter->movement(
            $movement,
            $this->documents->of($movement),
            $this->journal->of('movement', (int) $movement->getId()),
        ));
    }

    #[Route('/{id}/viewed', name: 'api_wh_movement_viewed', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function viewed(WhMovement $movement, ViewJournal $views): JsonResponse
    {
        return $this->json(['logged' => $views->viewed($movement, $this->me())]);
    }

    /**
     * Новий рух. multipart: data — JSON полів форми, files[] — накладна (необов'язково).
     * Або application/json без файлів.
     */
    #[Route('', name: 'api_wh_movement_create', methods: ['POST'])]
    #[IsGranted('PLAN_WAREHOUSE_EDIT')]
    public function create(Request $request, MovementWriter $writer): JsonResponse
    {
        $raw = $request->request->get('data');
        $data = is_string($raw) ? json_decode($raw, true) : $request->getPayload()->all();

        if (! is_array($data)) {
            return $this->error('Форма прийшла пошкодженою — оновіть сторінку.');
        }

        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter((array) $request->files->get('files', []), static fn ($f) => $f instanceof UploadedFile));

        try {
            $movement = $writer->create($data, $files, $this->me());
        } catch (WarehouseException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->presenter->movement(
            $movement,
            $this->documents->of($movement),
            $this->journal->of('movement', (int) $movement->getId()),
        ), Response::HTTP_CREATED);
    }
}
