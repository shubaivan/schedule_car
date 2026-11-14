<?php

namespace App\Warehouse\Controller\Api;

use App\Entity\TelegramUser;
use App\Warehouse\Api\WarehousePresenter;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Entity\WhDocument;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\CategoryScope;
use App\Warehouse\Enum\DocumentType;
use App\Warehouse\Enum\ItemState;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Exception\WarehouseException;
use App\Warehouse\Repository\WhActivityRepository;
use App\Warehouse\Repository\WhCategoryRepository;
use App\Warehouse\Repository\WhClientRepository;
use App\Warehouse\Repository\WhItemRepository;
use App\Warehouse\Repository\WhSiteRepository;
use App\Warehouse\Service\ActivityLog;
use App\Warehouse\Service\DocumentStore;
use App\Warehouse\Service\WarehouseDirectory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Довідники складу, журнал і документи для «Плану». */
#[Route('/api/warehouse')]
#[IsGranted('PLAN_WAREHOUSE_VIEW')]
class WarehouseApiController extends AbstractWarehouseApiController
{
    public function __construct(
        private readonly WarehousePresenter $presenter,
        private readonly DocumentStore $documents,
        private readonly ActivityLog $activity,
    ) {
    }

    /** Усе, що потрібно спискам і формам: категорії, місця, клієнти, типи рухів, одиниці. */
    #[Route('/meta', name: 'api_wh_meta', methods: ['GET'])]
    public function meta(
        WhCategoryRepository $categories,
        WhSiteRepository $sites,
        WhClientRepository $clients,
        WhItemRepository $items,
        WarehouseDirectory $directory,
    ): JsonResponse {
        $itemCategories = $categories->of(CategoryScope::Item, false);

        return $this->json([
            'categories' => array_map(fn ($c) => [...$this->presenter->category($c), 'next' => $directory->nextNumber($c)], $itemCategories),
            'sites' => array_map($this->presenter->site(...), $sites->listed(false)),
            'clients' => array_map($this->presenter->client(...), $clients->findBy([], ['name' => 'ASC'])),
            'movementTypes' => WarehousePresenter::movementTypes(),
            'states' => array_map(static fn (ItemState $s) => ['value' => $s->value, 'label' => $s->label()], ItemState::cases()),
            'trackings' => array_map(static fn (Tracking $t) => ['value' => $t->value, 'label' => $t->label()], Tracking::cases()),
            'documentTypes' => array_map(static fn (DocumentType $t) => ['value' => $t->value, 'label' => $t->label()], DocumentType::cases()),
            'units' => $items->units(),
            'actions' => array_map(static fn (ActivityAction $a) => ['value' => $a->value, 'label' => $a->label(), 'emoji' => $a->emoji()], ActivityAction::cases()),
            'subjectTypes' => ActivityLog::TYPES,
            'can' => ['edit' => $this->isGranted('PLAN_WAREHOUSE_EDIT')],
            'today' => (new DateTime('today'))->format('Y-m-d'),
        ]);
    }

    /** Журнал складу — той самий wh_activity і ті самі фільтри, що на /sklad/journal. */
    #[Route('/journal', name: 'api_wh_journal', methods: ['GET'])]
    public function journal(Request $request, WhActivityRepository $journal, EntityManagerInterface $em): JsonResponse
    {
        $q = $request->query;
        $user = $q->getInt('user') ? $em->find(TelegramUser::class, $q->getInt('user')) : null;
        $type = (string) $q->get('type', '');
        $from = DateTime::createFromFormat('!Y-m-d', (string) $q->get('from', '')) ?: null;
        $to = DateTime::createFromFormat('!Y-m-d', (string) $q->get('to', '')) ?: null;

        $entries = $journal->search(
            $user,
            ActivityAction::tryFrom((string) $q->get('action', '')),
            isset(ActivityLog::TYPES[$type]) ? $type : null,
            $from,
            $to,
        );

        return $this->json([
            'items' => array_map($this->presenter->activity(...), $entries),
            'people' => array_map($this->presenter->person(...), $journal->people()),
        ]);
    }

    /** Файл документа: фото й PDF — у браузері, решта (і ?download=1) — завантаженням. */
    #[Route('/documents/{id}', name: 'api_wh_document', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function document(WhDocument $document, Request $request): Response
    {
        $inline = ! $request->query->getBoolean('download') &&
            (str_starts_with($document->getMime(), 'image/') || $document->getMime() === 'application/pdf');

        $response = new Response($this->documents->read($document));
        $response->headers->set('Content-Type', $document->getMime());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $document->getOriginalName(),
            'file-' . $document->getId(),
        ));

        return $response;
    }

    /**
     * Прибрати документ. Підтвердження — тостом «Скасувати» на фронті: запит
     * приходить сюди лише після 8 секунд (видалення файлу не відновити).
     */
    #[Route('/documents/{id}', name: 'api_wh_document_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('PLAN_WAREHOUSE_EDIT')]
    public function deleteDocument(WhDocument $document): JsonResponse
    {
        $owner = $document->getOwner();
        $name = $document->getOriginalName();

        try {
            $this->documents->remove($document, $this->me());
        } catch (WarehouseException $e) {
            return $this->error($e->getMessage(), Response::HTTP_FORBIDDEN);
        }

        if ($owner !== null) {
            $this->activity->record(ActivityAction::DocumentRemove, $owner, $this->me(), WhActivity::CHANNEL_ADMIN, $name);
        }

        return $this->json(['removed' => true]);
    }
}
