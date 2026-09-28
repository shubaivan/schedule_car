<?php

namespace App\Warehouse\Controller;

use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhDocument;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\DocumentType;
use App\Warehouse\Exception\WarehouseException;
use App\Warehouse\Service\DocumentStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Документи складу: завантаження на будь-яку картку, віддача, видалення. */
#[Route('/sklad/documents')]
class DocumentController extends AbstractWarehouseController
{
    private const OWNERS = [
        'client' => WhClient::class,
        'site' => WhSite::class,
        'item' => WhItem::class,
        'movement' => WhMovement::class,
    ];

    public function __construct(
        private DocumentStore $documents,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/{owner}/{id}', name: 'wh_document_upload', methods: ['POST'], requirements: ['owner' => 'client|site|item|movement', 'id' => '\d+'])]
    public function upload(Request $request, string $owner, int $id): Response
    {
        $this->checkCsrf($request);

        $entity = $this->em->find(self::OWNERS[$owner], $id) ?? throw new NotFoundHttpException();
        $type = DocumentType::tryFrom((string) $request->request->get('type', '')) ?? DocumentType::Other;
        $saved = 0;

        try {
            foreach ($request->files->all('files') as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                if (! $file->isValid()) {
                    throw new WarehouseException('Файл не долетів: ' . $file->getErrorMessage());
                }

                $document = $this->documents->attach(
                    $entity,
                    $this->user(),
                    (string) file_get_contents($file->getPathname()),
                    (string) ($file->getClientOriginalName() ?: $file->getFilename()),
                    (string) ($file->getMimeType() ?: $file->getClientMimeType()),
                    $type,
                );
                $this->log(ActivityAction::DocumentAdd, $entity, sprintf('%s: %s', $type->label(), $document->getOriginalName()));
                ++$saved;
            }

            $saved > 0
                ? $this->addFlash('ok', sprintf('Додано документів: %d. Копія на Google Диск поїде автоматично.', $saved))
                : $this->addFlash('error', 'Оберіть файл.');
        } catch (WarehouseException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($entity);
    }

    #[Route('/{id}', name: 'wh_document', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(WhDocument $document): Response
    {
        $response = new Response($this->documents->read($document));
        $response->headers->set('Content-Type', $document->getMime());
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            // PDF і фото відкриваються в браузері — так їх швидше переглянути.
            str_starts_with($document->getMime(), 'image/') || $document->getMime() === 'application/pdf'
                ? ResponseHeaderBag::DISPOSITION_INLINE
                : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $document->getOriginalName(),
            'file-' . $document->getId(),
        ));

        return $response;
    }

    #[Route('/{id}/delete', name: 'wh_document_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, WhDocument $document): Response
    {
        $this->checkCsrf($request);

        $owner = $document->getOwner();
        $name = $document->getOriginalName();

        try {
            $this->documents->remove($document, $this->user());

            if ($owner !== null) {
                $this->log(ActivityAction::DocumentRemove, $owner, $name);
            }
            $this->addFlash('ok', sprintf('Документ «%s» прибрано. Копія на Google Диску лишилась.', $name));
        } catch (WarehouseException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $owner !== null ? $this->back($owner) : $this->redirectToRoute('wh_items');
    }

    private function back(WhClient|WhSite|WhItem|WhMovement $owner): RedirectResponse
    {
        return match (true) {
            $owner instanceof WhClient => $this->redirectToRoute('wh_client', ['id' => $owner->getId()]),
            $owner instanceof WhSite => $this->redirectToRoute('wh_site', ['id' => $owner->getId()]),
            $owner instanceof WhItem => $this->redirectToRoute('wh_item', ['id' => $owner->getId()]),
            default => $this->redirectToRoute('wh_movement', ['id' => $owner->getId()]),
        };
    }
}
