<?php

namespace App\Supply\Controller;

use App\Entity\TelegramUser;
use App\Supply\Entity\SupplyAttachment;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\AttachmentType;
use App\Supply\Exception\SupplyException;
use App\Supply\Service\AttachFile;
use App\Supply\Service\RequestPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/** Документи заявки: завантаження, віддача, видалення. */
#[Route('/api/supply')]
class AttachmentApiController extends AbstractController
{
    public function __construct(
        private AttachFile $attachFile,
        private RequestPresenter $presenter,
    ) {
    }

    #[Route(
        '/requests/{id}/attachments',
        name: 'api_supply_attachment_upload',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function upload(SupplyRequest $supplyRequest, Request $request): JsonResponse
    {
        $file = $request->files->get('file');

        if ($file === null) {
            return $this->error('Файл не надіслано.');
        }

        if (! $file->isValid()) {
            // Найчастіше це post_max_size / upload_max_filesize у php.ini.
            return $this->error('Файл не долетів: ' . $file->getErrorMessage());
        }

        $type = AttachmentType::tryFrom((string) $request->request->get('type', AttachmentType::Other->value));

        if ($type === null) {
            return $this->error('Невідомий тип документа.');
        }

        try {
            $this->attachFile->__invoke(
                $supplyRequest,
                $this->user(),
                (string) file_get_contents($file->getPathname()),
                (string) ($file->getClientOriginalName() ?: $file->getFilename()),
                (string) ($file->getMimeType() ?: $file->getClientMimeType()),
                $type,
            );
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->presenter->detail($supplyRequest), Response::HTTP_CREATED);
    }

    /**
     * Файли лежать поза public/, тож віддаємо їх лише тут — після перевірки
     * сесії файрволом і належності файлу саме цій заявці.
     */
    #[Route(
        '/requests/{id}/attachments/{attachmentId}',
        name: 'api_supply_attachment_download',
        methods: ['GET'],
        requirements: ['id' => '\d+', 'attachmentId' => '\d+'],
    )]
    public function download(SupplyRequest $supplyRequest, int $attachmentId): Response
    {
        $attachment = $this->attachmentOf($supplyRequest, $attachmentId);

        if ($attachment === null) {
            throw $this->createNotFoundException('Файл не знайдено.');
        }

        $response = new Response($this->attachFile->read($attachment));
        $response->headers->set('Content-Type', $attachment->getMime());
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $attachment->getOriginalName(),
            // Кирилиця в імені ламає старі клієнти — поруч даємо ASCII-запасний варіант.
            'file-' . $attachment->getId(),
        ));

        return $response;
    }

    #[Route(
        '/requests/{id}/attachments/{attachmentId}',
        name: 'api_supply_attachment_delete',
        methods: ['DELETE'],
        requirements: ['id' => '\d+', 'attachmentId' => '\d+'],
    )]
    public function delete(SupplyRequest $supplyRequest, int $attachmentId): JsonResponse
    {
        $attachment = $this->attachmentOf($supplyRequest, $attachmentId);

        if ($attachment === null) {
            return $this->error('Файл не знайдено.');
        }

        try {
            $this->attachFile->remove($attachment, $this->user());
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->presenter->detail($supplyRequest));
    }

    /** Шукаємо серед файлів саме цієї заявки — чужий за id не підсунути. */
    private function attachmentOf(SupplyRequest $request, int $attachmentId): ?SupplyAttachment
    {
        foreach ($request->getAttachments() as $attachment) {
            if ($attachment->getId() === $attachmentId) {
                return $attachment;
            }
        }

        return null;
    }

    private function user(): TelegramUser
    {
        /** @var TelegramUser $user */
        $user = $this->getUser();

        return $user;
    }

    private function error(string $message): JsonResponse
    {
        return $this->json(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
