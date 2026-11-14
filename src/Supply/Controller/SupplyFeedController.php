<?php

namespace App\Supply\Controller;

use App\Entity\TelegramUser;
use App\Supply\Entity\SupplyAttachment;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\AttachmentType;
use App\Supply\Exception\SupplyException;
use App\Supply\Service\AddComment;
use App\Supply\Service\AttachFile;
use App\Supply\Service\SupplyFeed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Стрічка заявки для «Плану»: читання (опитування) і одне поле вводу —
 * коментар і/або файли одним надсиланням, як у чаті задачі.
 * Пише тими самими сервісами, що й бот і стара картка (AddComment, AttachFile),
 * тож права, сповіщення й Drive-дзеркало не змінюються.
 */
#[Route('/api/supply/requests/{id}', requirements: ['id' => '\d+'])]
class SupplyFeedController extends AbstractController
{
    public function __construct(private SupplyFeed $feed)
    {
    }

    #[Route('/feed', name: 'api_supply_request_feed', methods: ['GET'])]
    public function view(SupplyRequest $supplyRequest): JsonResponse
    {
        return $this->json($this->feed->feed($supplyRequest, $this->me()));
    }

    #[Route('/feed', name: 'api_supply_request_feed_post', methods: ['POST'])]
    public function post(SupplyRequest $supplyRequest, Request $request, AddComment $addComment, AttachFile $attachFile): JsonResponse
    {
        $me = $this->me();
        $text = trim((string) $request->request->get('text', ''));
        /** @var list<UploadedFile|null> $files */
        $files = array_values(array_filter((array) $request->files->get('files', [])));
        $type = AttachmentType::tryFrom((string) $request->request->get('type', ''));

        if ($text === '' && ! $files) {
            return $this->error('Напишіть коментар або додайте файл.');
        }

        // Спершу перевіряємо все, потім пишемо: щоб не лишити коментар без файлів через помилку прав.
        if ($text !== '' && ! $this->feed->canComment($supplyRequest, $me)) {
            return $this->error('Коментувати заявку можуть її автор, менеджер із постачання та директор.', Response::HTTP_FORBIDDEN);
        }

        if ($files && ! $this->feed->canAttach($supplyRequest, $me)) {
            return $this->error('Прикріпити файл можуть автор заявки й менеджер із постачання.', Response::HTTP_FORBIDDEN);
        }

        // Ті самі перевірки, що в AttachFile, але до запису: інакше коментар збережеться
        // (і піде в Telegram), а файл ні — і повтор надсилання дасть дубль.
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                return $this->error('Файл не долетів: ' . ($file instanceof UploadedFile ? $file->getErrorMessage() : 'порожній'));
            }

            $size = (int) $file->getSize();

            if ($size === 0 || $size > AttachFile::MAX_SIZE) {
                return $this->error(sprintf('«%s»: файл порожній або більший за %d МБ.', $file->getClientOriginalName(), AttachFile::MAX_SIZE / 1024 / 1024));
            }

            if (! in_array(strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType())), AttachFile::ALLOWED_MIME, true)) {
                return $this->error(sprintf('«%s»: приймаємо PDF, фото та документи Word/Excel.', $file->getClientOriginalName()));
            }
        }

        try {
            if ($text !== '') {
                $addComment($supplyRequest, $me, $text);
            }

            foreach ($files as $file) {
                $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
                $attachFile(
                    $supplyRequest,
                    $me,
                    (string) file_get_contents($file->getPathname()),
                    (string) ($file->getClientOriginalName() ?: $file->getFilename()),
                    $mime,
                    $type ?? SupplyFeed::guessType($mime),
                );
            }
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->feed->feed($supplyRequest, $me), Response::HTTP_CREATED);
    }

    /**
     * Файл для перегляду в браузері: фото — у переглядачі «Плану», PDF — у вкладці.
     * Решту (Word, Excel, HEIC) браузер сам не покаже — їх віддаємо на скачування.
     */
    #[Route('/attachments/{attachmentId}/inline', name: 'api_supply_attachment_inline', methods: ['GET'], requirements: ['attachmentId' => '\d+'])]
    public function inline(SupplyRequest $supplyRequest, int $attachmentId, AttachFile $attachFile): Response
    {
        $attachment = $supplyRequest->getAttachments()->findFirst(static fn (int $k, SupplyAttachment $a) => $a->getId() === $attachmentId);

        if (! $attachment instanceof SupplyAttachment) {
            throw $this->createNotFoundException('Файл не знайдено.');
        }

        $inline = in_array($attachment->getMime(), SupplyFeed::INLINE_MIME, true);
        $response = new Response($attachFile->read($attachment));
        $response->headers->set('Content-Type', $attachment->getMime());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, max-age=3600');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $attachment->getOriginalName(),
            'file-' . $attachment->getId(),
        ));

        return $response;
    }

    private function me(): TelegramUser
    {
        /** @var TelegramUser $user */
        $user = $this->getUser();

        return $user;
    }

    private function error(string $message, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): JsonResponse
    {
        return $this->json(['error' => $message], $status);
    }
}
