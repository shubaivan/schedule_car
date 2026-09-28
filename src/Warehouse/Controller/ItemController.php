<?php

namespace App\Warehouse\Controller;

use App\Warehouse\Entity\WhItem;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\CategoryScope;
use App\Warehouse\Enum\DocumentType;
use App\Warehouse\Enum\ItemState;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Exception\WarehouseException;
use App\Warehouse\Repository\WhActivityRepository;
use App\Warehouse\Repository\WhCategoryRepository;
use App\Warehouse\Repository\WhItemRepository;
use App\Warehouse\Repository\WhMovementRepository;
use App\Warehouse\Repository\WhSiteRepository;
use App\Warehouse\Service\DocumentStore;
use App\Warehouse\Service\WarehouseDirectory;
use App\Warehouse\Service\WarehouseLinks;
use App\Warehouse\Service\WarehouseStock;
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

        return $this->render('warehouse/item.html.twig', [
            'item' => $item,
            'places' => $this->stock->placesOf($item),
            'total' => $this->stock->total($item),
            'history' => $this->movements->historyOf($item),
            'documents' => $this->documents->of($item),
            'documentTypes' => DocumentType::cases(),
            'journal' => $this->journal->of('item', (int) $item->getId()),
            'qrSvg' => $this->links->qrSvg($item),
            'shareUrl' => $this->links->url($item),
        ]);
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

                return $this->redirectToRoute('wh_item', ['id' => $item->getId()]);
            } catch (WarehouseException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('warehouse/item_form.html.twig', [
            'item' => $item,
            'values' => $values,
            'error' => $error,
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
}
