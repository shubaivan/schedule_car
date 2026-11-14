<?php

namespace App\Warehouse\Controller\Api;

use App\Plan\EventStore\PlanEventRecorder;
use App\Warehouse\Api\ItemCatalog;
use App\Warehouse\Api\ItemFeed;
use App\Warehouse\Api\ItemMentions;
use App\Warehouse\Api\MovementLoader;
use App\Warehouse\Api\ViewJournal;
use App\Warehouse\Api\WarehousePresenter;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\DocumentType;
use App\Warehouse\Exception\WarehouseException;
use App\Warehouse\Service\ActivityLog;
use App\Warehouse\Service\DocumentStore;
use App\Warehouse\Service\WarehouseDirectory;
use App\Warehouse\Service\WarehouseFeed;
use App\Warehouse\Service\WarehouseLinks;
use App\Warehouse\Service\WarehouseStock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Позиції складу для «Плану»: список із фільтрами, картка (split), стрічка подій,
 * QR, документи, нова позиція й правка. Читання стрічки й картки НЕ пишуть
 * у журнал — «відкрив картку» фронт надсилає окремо й один раз (POST …/viewed).
 */
#[Route('/api/warehouse/items')]
#[IsGranted('PLAN_WAREHOUSE_VIEW')]
class ItemApiController extends AbstractWarehouseApiController
{
    public function __construct(
        private readonly ItemCatalog $catalog,
        private readonly WarehousePresenter $presenter,
        private readonly WarehouseStock $stock,
        private readonly MovementLoader $loader,
        private readonly DocumentStore $documents,
        private readonly WarehouseDirectory $directory,
        private readonly ActivityLog $activity,
        private readonly WarehouseFeed $feed,
        private readonly WarehouseLinks $links,
        private readonly EntityManagerInterface $em,
        private readonly PlanEventRecorder $events,
        #[Autowire('%env(TELEGRAM_BOT_USERNAME)%')]
        private readonly string $botUsername = '',
    ) {
    }

    #[Route('', name: 'api_wh_items', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $q = $request->query;

        return $this->json($this->catalog->search(
            trim((string) $q->get('q', '')),
            $q->getInt('category') ?: null,
            (string) $q->get('place', ''),
            $q->get('state') !== null ? (string) $q->get('state') : null,
            $q->get('tracking') !== null ? (string) $q->get('tracking') : null,
            $q->getBoolean('off'),
        ));
    }

    #[Route('/{id}', name: 'api_wh_item', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(WhItem $item, ItemMentions $mentions): JsonResponse
    {
        return $this->json([
            ...$this->card($item),
            'mentions' => $mentions->of($item, $this->me()),
        ]);
    }

    /** Картку відкрили (раз на відкриття, не на рендер) — у журнал складу, з обмеженням частоти. */
    #[Route('/{id}/viewed', name: 'api_wh_item_viewed', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function viewed(WhItem $item, ViewJournal $views): JsonResponse
    {
        return $this->json(['logged' => $views->viewed($item, $this->me())]);
    }

    #[Route('/{id}/feed', name: 'api_wh_item_feed', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function feed(WhItem $item, ItemFeed $feed): JsonResponse
    {
        return $this->json($feed->feed($item, $this->me()));
    }

    #[Route('/{id}/qr.svg', name: 'api_wh_item_qr', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function qr(WhItem $item): Response
    {
        return new Response($this->links->qrSvg($item), headers: [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    #[Route('', name: 'api_wh_item_create', methods: ['POST'])]
    #[IsGranted('PLAN_WAREHOUSE_EDIT')]
    public function create(Request $request): JsonResponse
    {
        $item = new WhItem();

        $data = $this->payload($request);

        try {
            // Позиція, рядок журналу й ItemCreated — одна транзакція: або все, або нічого.
            $this->events->transactional(function () use ($item, $data): void {
                $this->directory->saveItem($item, $data, $this->me());
                $this->activity->recordStrict(ActivityAction::Create, $item, $this->me(), WhActivity::CHANNEL_ADMIN);
            });
        } catch (WarehouseException $e) {
            return $this->error($e->getMessage());
        }

        $this->feed->itemCreated($item, $this->me());

        return $this->json($this->card($item), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_wh_item_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('PLAN_WAREHOUSE_EDIT')]
    public function update(WhItem $item, Request $request): JsonResponse
    {
        $before = $this->snapshot($item);
        $data = $this->payload($request);

        try {
            $this->events->transactional(function () use ($item, $data, $before): void {
                $this->directory->saveItem($item, $data, $this->me());
                $changed = array_keys(array_diff_assoc($this->snapshot($item), $before));

                if ($changed !== []) {
                    $this->activity->recordStrict(ActivityAction::Update, $item, $this->me(), WhActivity::CHANNEL_ADMIN, implode(', ', $changed));
                }
            });
        } catch (WarehouseException $e) {
            // Поля могли змінитись у пам'яті до перевірки, що впала, — повертаємо як у базі.
            $this->em->refresh($item);

            return $this->error($e->getMessage());
        }

        return $this->json($this->card($item));
    }

    #[Route('/{id}/documents', name: 'api_wh_item_documents', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('PLAN_WAREHOUSE_EDIT')]
    public function upload(WhItem $item, Request $request): JsonResponse
    {
        $type = DocumentType::tryFrom((string) $request->request->get('type', '')) ?? DocumentType::Other;
        /** @var list<UploadedFile|null> $files */
        $files = array_values(array_filter((array) $request->files->get('files', [])));

        if ($files === []) {
            return $this->error('Оберіть файл.');
        }

        $saved = [];
        $error = null;

        // Документи, що лягли, рядок журналу й FileAdded — одна транзакція. Файл, на якому
        // зупинились (не той тип, завеликий), — помилкою у відповіді; попередні лишаються (як у Twig).
        $this->events->transactional(function () use ($item, $type, $files, &$saved, &$error): void {
            try {
                foreach ($files as $file) {
                    if (! $file instanceof UploadedFile || ! $file->isValid()) {
                        throw new WarehouseException('Файл не долетів: ' . ($file instanceof UploadedFile ? $file->getErrorMessage() : 'порожній'));
                    }

                    $document = $this->documents->attach(
                        $item,
                        $this->me(),
                        (string) file_get_contents($file->getPathname()),
                        (string) ($file->getClientOriginalName() ?: $file->getFilename()),
                        (string) ($file->getMimeType() ?: $file->getClientMimeType()),
                        $type,
                    );
                    $saved[] = $document->getOriginalName();
                }
            } catch (WarehouseException $e) {
                $error = $e->getMessage();
            }

            $this->journalUpload($item, $type, $saved);
        });

        if ($error !== null) {
            return $this->json(['error' => $error, 'saved' => count($saved), ...$this->card($item)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->card($item));
    }

    /**
     * Одне надсилання — один рядок журналу й одна подія FileAdded, навіть якщо файлів кілька.
     *
     * @param list<string> $names
     */
    private function journalUpload(WhItem $item, DocumentType $type, array $names): void
    {
        if ($names !== []) {
            $this->activity->recordStrict(ActivityAction::DocumentAdd, $item, $this->me(), WhActivity::CHANNEL_ADMIN, sprintf('%s: %s', $type->label(), implode(', ', $names)));
        }
    }

    /** @return array<string, mixed> */
    private function card(WhItem $item): array
    {
        return $this->presenter->item(
            $item,
            $this->stock->total($item),
            $this->stock->placesOf($item),
            $this->documents->of($item),
            // Те саме, що WhMovementRepository::historyOf, але без запиту на кожен рух.
            $this->loader->load(
                $this->loader->filter()->andWhere('EXISTS (SELECT 1 FROM App\Warehouse\Entity\WhMovementLine hl WHERE hl.movement = m AND hl.item = :item)')->setParameter('item', $item),
                50,
            ),
            trim($this->botUsername) !== '' ? $this->links->url($item) : null,
        );
    }

    /**
     * Поля форми «Плану» у форматі Twig-форми (attrName[]/attrValue[]), щоб
     * WarehouseDirectory::saveItem перевіряв їх тими самими правилами.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $data = $request->getPayload()->all();
        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $data['attrName'] = [];
        $data['attrValue'] = [];

        foreach ($attributes as $pair) {
            if (is_array($pair)) {
                $data['attrName'][] = (string) ($pair['name'] ?? '');
                $data['attrValue'][] = (string) ($pair['value'] ?? '');
            }
        }

        unset($data['attributes']);

        return $data;
    }

    /** @return array<string, string> що людина бачить у картці — для «змінив: назву, ставку» */
    private function snapshot(WhItem $item): array
    {
        return [
            'назву' => $item->getName(),
            'категорію' => (string) $item->getCategory()->getId(),
            'інв. номер' => $item->getInventoryNumber(),
            'облік' => $item->getTracking()->value,
            'одиницю' => $item->getUnit(),
            'стан' => $item->getState()->value,
            'опис' => (string) $item->getDescription(),
            'характеристики' => (string) json_encode($item->getAttributes(), JSON_UNESCAPED_UNICODE),
            'серійний номер' => (string) $item->getSerialNumber(),
            'постачальника' => (string) $item->getSupplier()?->getId(),
            'ціну' => (string) $item->getPurchasePrice(),
            'дату купівлі' => (string) $item->getPurchasedAt()?->format('Y-m-d'),
            'ставку оренди' => (string) $item->getRentalRate(),
        ];
    }
}
