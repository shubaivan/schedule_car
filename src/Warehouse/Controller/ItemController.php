<?php

namespace App\Warehouse\Controller;

use App\Supply\Repository\SupplierRepository;
use App\Warehouse\Entity\WhDocument;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\CategoryScope;
use App\Warehouse\Enum\DocumentType;
use App\Warehouse\Enum\ItemState;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Exception\WarehouseException;
use App\Warehouse\Repository\WhActivityRepository;
use App\Warehouse\Repository\WhCategoryRepository;
use App\Warehouse\Repository\WhDocumentRepository;
use App\Warehouse\Repository\WhItemRepository;
use App\Warehouse\Repository\WhMovementRepository;
use App\Warehouse\Repository\WhSiteRepository;
use App\Warehouse\Service\DocumentStore;
use App\Warehouse\Service\WarehouseDirectory;
use App\Warehouse\Service\WarehouseFeed;
use App\Warehouse\Service\WarehouseLinks;
use App\Warehouse\Service\WarehouseStock;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Позиції складу: список, картка, форма, QR і друк наклейок. */
#[Route('/sklad')]
class ItemController extends AbstractWarehouseController
{
    public function __construct(
        private WhItemRepository $items,
        private WhCategoryRepository $categories,
        private WhSiteRepository $sites,
        private WhMovementRepository $movements,
        private WarehouseDirectory $directory,
        private WarehouseStock $stock,
        private DocumentStore $documents,
        private WarehouseLinks $links,
        private WhActivityRepository $journal,
        private WhDocumentRepository $documentRows,
        private SupplierRepository $suppliers,
        private WarehouseFeed $feed,
    ) {
    }

    #[Route('', name: 'wh_items', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $categoryId = $request->query->getInt('category');
        $category = $categoryId ? $this->categories->find($categoryId) : null;
        $place = (string) $request->query->get('place', '');

        $items = $this->items->search($query, $category, $place, $request->query->getBoolean('off'));

        return $this->render('warehouse/items.html.twig', [
            'items' => $items,
            'totals' => array_map(fn (WhItem $item) => $item->isUnit() ? null : $this->stock->total($item), $items),
            'covers' => $this->documentRows->coverPhotos($items),
            'sites' => $this->sites->listed(false),
            'categories' => $this->categories->of(CategoryScope::Item, false),
            'filters' => ['q' => $query, 'category' => $category?->getId(), 'place' => $place, 'off' => $request->query->getBoolean('off')],
        ]);
    }

    #[Route('/items/new', name: 'wh_item_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->form($request, new WhItem());
    }

    #[Route('/items/{id}/edit', name: 'wh_item_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, WhItem $item): Response
    {
        return $this->form($request, $item);
    }

    #[Route('/items/{id}', name: 'wh_item', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Request $request, WhItem $item): Response
    {
        $this->viewed($request, $item);
        $documents = $this->documents->of($item);

        return $this->render('warehouse/item.html.twig', [
            'item' => $item,
            'places' => $this->stock->placesOf($item),
            'total' => $this->stock->total($item),
            'history' => $this->movements->historyOf($item),
            // Фото позиції — окремою галереєю вгорі, решта — таблицею документів.
            'photos' => array_values(array_filter($documents, fn (WhDocument $d) => $d->isViewablePhoto())),
            'documents' => array_values(array_filter($documents, fn (WhDocument $d) => ! $d->isViewablePhoto())),
            'documentTypes' => DocumentType::cases(),
            'journal' => $this->journal->of('item', (int) $item->getId()),
            'qrSvg' => $this->links->qrSvg($item),
            'shareUrl' => $this->links->url($item),
        ]);
    }

    /** Підказка для поля «Постачальник / виробник»: активні зі спільного довідника. */
    #[Route('/suppliers', name: 'wh_suppliers', methods: ['GET'])]
    public function suppliers(Request $request): JsonResponse
    {
        $results = [];

        foreach ($this->suppliers->search((string) $request->query->get('q', ''), 30) as $supplier) {
            $results[] = ['id' => $supplier->getId(), 'text' => $supplier->getName(), 'edrpou' => $supplier->getEdrpou()];
        }

        return $this->json(['results' => $results]);
    }

    #[Route('/items/{id}/qr.svg', name: 'wh_item_qr', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function qr(WhItem $item): Response
    {
        return new Response($this->links->qrSvg($item), headers: [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => sprintf('inline; filename="qr-%s.svg"', $item->getId()),
        ]);
    }

    /**
     * Аркуш наклейок: обрані позиції (або одна) — QR, інвентарний номер і назва.
     * Друкується з браузера на звичайний A4 чи на рулон термопринтера.
     */
    #[Route('/labels', name: 'wh_labels', methods: ['GET'])]
    public function labels(Request $request): Response
    {
        $ids = array_filter(array_map('intval', (array) $request->query->all('ids')));
        $copies = max(1, min(50, $request->query->getInt('copies', 1)));
        $items = $ids ? $this->items->findBy(['id' => $ids], ['inventoryNumber' => 'ASC']) : [];

        $labels = [];

        foreach ($items as $item) {
            $svg = $this->links->qrSvg($item);
            $this->log(ActivityAction::Labels, $item, sprintf('%d шт.', $copies));

            for ($i = 0; $i < $copies; ++$i) {
                $labels[] = ['item' => $item, 'svg' => $svg];
            }
        }

        return $this->render('warehouse/labels.html.twig', [
            'labels' => $labels,
            'size' => $request->query->get('size') === 'small' ? 'small' : 'large',
            'copies' => $copies,
            'ids' => $ids,
        ]);
    }

    private function form(Request $request, WhItem $item): Response
    {
        $error = null;
        $values = $request->isMethod('POST') ? $this->fields($request) : null;

        if ($values !== null) {
            $this->checkCsrf($request);

            $isNew = $item->getId() === null;

            try {
                $this->directory->saveItem($item, $values, $this->user());
                $this->log($isNew ? ActivityAction::Create : ActivityAction::Update, $item);
                $this->addFlash('ok', sprintf('Збережено: %s.', $item->getLabel()));
                $this->attachPhotos($request, $item);

                if ($isNew) {
                    $this->feed->itemCreated($item, $this->user());
                }

                return $this->redirectToRoute('wh_item', ['id' => $item->getId()]);
            } catch (WarehouseException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('warehouse/item_form.html.twig', [
            'item' => $item,
            'values' => $values,
            'error' => $error,
            'supplierChoice' => $this->supplierChoice($item, $values),
            'units' => $this->items->units(),
            'categories' => $categories = $this->categories->of(CategoryScope::Item),
            'trackings' => Tracking::cases(),
            'states' => [ItemState::Active, ItemState::Repair],
            // Підказка номера й характеристик міняється разом із вибраною категорією.
            'categoryHints' => array_map(fn ($c) => [
                'id' => $c->getId(),
                'next' => $this->directory->nextNumber($c),
                'attributes' => $c->getAttributes(),
            ], $categories),
        ]);
    }

    /**
     * Фото з форми. Позиція на цей момент уже збережена, тож зіпсоване фото
     * її не відкочує — лише каже, яке не лягло, і його можна додати з картки.
     */
    private function attachPhotos(Request $request, WhItem $item): void
    {
        $saved = 0;

        foreach ($request->files->all('photos') as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            try {
                if (! $file->isValid()) {
                    throw new WarehouseException('файл не долетів: ' . $file->getErrorMessage());
                }

                $document = $this->documents->attach(
                    $item,
                    $this->user(),
                    (string) file_get_contents($file->getPathname()),
                    (string) ($file->getClientOriginalName() ?: $file->getFilename()),
                    (string) ($file->getMimeType() ?: $file->getClientMimeType()),
                    DocumentType::Photo,
                );
                $this->log(ActivityAction::DocumentAdd, $item, sprintf('%s: %s', DocumentType::Photo->label(), $document->getOriginalName()));
                ++$saved;
            } catch (WarehouseException $e) {
                $this->addFlash('error', sprintf('Фото «%s» не додано: %s', $file->getClientOriginalName(), $e->getMessage()));
            }
        }

        if ($saved > 0) {
            $this->addFlash('ok', sprintf('Додано фото: %d.', $saved));
        }
    }

    /**
     * Що стоїть у полі постачальника при показі форми: збережений, або те,
     * що обрали/дописали перед помилкою — щоб не набирати вдруге.
     *
     * @return array{id: string, text: string}|null
     */
    private function supplierChoice(WhItem $item, ?array $values): ?array
    {
        if ($values === null) {
            $supplier = $item->getSupplier();

            return $supplier ? ['id' => (string) $supplier->getId(), 'text' => $supplier->getName()] : null;
        }

        $value = trim((string) ($values['supplierId'] ?? ''));

        if (str_starts_with($value, WarehouseDirectory::NEW)) {
            return ['id' => $value, 'text' => substr($value, strlen(WarehouseDirectory::NEW))];
        }

        $supplier = ctype_digit($value) ? $this->suppliers->find((int) $value) : null;

        return $supplier ? ['id' => $value, 'text' => $supplier->getName()] : null;
    }
}
