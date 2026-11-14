<?php

namespace App\Plan\Audit;

use App\Entity\TelegramUser;
use App\Plan\Event\FileDownloaded;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Supply\Entity\SupplyAttachment;
use App\Tasks\Entity\TaskAttachment;
use App\Warehouse\Entity\WhDocument;
use App\Warehouse\Service\ActivityLog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * «Хто скачав / відкрив файл» — подія FileDownloaded у журнал аудиту (лише аудит,
 * не Хроніка й не сповіщення).
 *
 * Слухаємо відповідь, а не правимо контролери файлів модулів: подія пишеться
 * лише коли файл справді віддано (200 — перевірки доступу пройдено, файл є).
 * Превʼю (thumb/view/page) — не скачування, їх не рахуємо. Докачування шматками
 * (206) і повтор того самого файлу тією ж людиною за 10 хв зводяться в один рядок
 * (AuditThrottle) — переглядач PDF інакше дав би десяток.
 *
 * Новий маршрут віддачі файлу (S5: /files/:id, /s/{token}) — додати в ROUTES.
 */
final class FileDownloadAudit
{
    /** Маршрут → тип файлу в payload. */
    public const array ROUTES = [
        'api_tasks_file_download' => 'task',
        'api_tasks_file_inline' => 'task',
        'api_supply_attachment_download' => 'supply',
        'wh_document' => 'wh',
    ];

    public function __construct(
        private readonly PlanEventRecorder $events,
        private readonly AuditThrottle $throttle,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -64)]
    public function onResponse(ResponseEvent $event): void
    {
        if (! $event->isMainRequest() || $event->getResponse()->getStatusCode() !== 200) {
            return;
        }

        $request = $event->getRequest();
        $fileType = self::ROUTES[(string) $request->attributes->get('_route')] ?? null;
        $user = $this->security->getUser();

        if ($fileType === null || ! $user instanceof TelegramUser) {
            return;
        }

        try {
            $file = $this->resolve($fileType, $request->attributes->all());

            if ($file === null) {
                return;
            }

            [$subject, $fileId, $name] = $file;

            if ($this->throttle->seenRecently(FileDownloaded::TYPE, $user->getId(), $subject, ['fileId' => $fileId, 'fileType' => $fileType])) {
                return;
            }

            $this->events->record(new FileDownloaded($subject, $user->getId(), fileId: $fileId, fileType: $fileType, name: mb_substr($name, 0, 255)));
        } catch (Throwable $e) {
            // Аудит — не причина не віддати файл.
            $this->logger->warning('plan: скачування не записано в журнал', ['route' => $request->attributes->get('_route'), 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $attributes атрибути запиту (параметри маршруту)
     *
     * @return array{0: SubjectRef, 1: int, 2: string}|null суб'єкт, id файлу, ім'я
     */
    private function resolve(string $fileType, array $attributes): ?array
    {
        switch ($fileType) {
            case 'task':
                $file = $this->em->find(TaskAttachment::class, (int) ($attributes['id'] ?? 0));

                return $file !== null ? [SubjectRef::task((int) $file->getTask()->getId()), (int) $file->getId(), $file->getOriginalName()] : null;

            case 'supply':
                $file = $this->em->find(SupplyAttachment::class, (int) ($attributes['attachmentId'] ?? 0));

                return $file !== null ? [SubjectRef::supply((int) $file->getRequest()->getId()), (int) $file->getId(), $file->getOriginalName()] : null;

            default:
                $file = $this->em->find(WhDocument::class, (int) ($attributes['id'] ?? 0));
                $owner = $file?->getOwner();

                if ($file === null || $owner === null) {
                    return null;
                }

                return [SubjectRef::of(SubjectType::fromWarehouse(ActivityLog::typeOf($owner)), (int) $owner->getId()), (int) $file->getId(), $file->getOriginalName()];
        }
    }
}
