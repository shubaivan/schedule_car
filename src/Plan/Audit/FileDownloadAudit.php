<?php

namespace App\Plan\Audit;

use App\Entity\TelegramUser;
use App\Plan\Event\FileDownloaded;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Supply\Entity\SupplyAttachment;
use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Service\TasksSection;
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
 * Це ЄДИНЕ місце, що пише FileDownloaded для людей у системі: контролери файлів
 * самі подію не пишуть (інакше дубль). Виняток — скачування за посиланням /s/{token}:
 * там людина анонімна, подію з shareLinkId і лічильник пише ShareService::recordDownload.
 * Новий маршрут віддачі файлу — додати в ROUTES.
 */
final class FileDownloadAudit
{
    /**
     * Маршрут → [тип файлу (null — з параметра маршруту {type}), параметр маршруту з id файлу].
     *
     * @var array<string, array{0: ?string, 1: string}>
     */
    public const array ROUTES = [
        'api_tasks_file_download' => ['task', 'id'],
        'api_tasks_file_inline' => ['task', 'id'],
        'api_supply_attachment_download' => ['supply', 'attachmentId'],
        'wh_document' => ['wh', 'id'],
        // S2: переглядач файлу заявки в «Плані»; S3: документ складу у Vue-складі (і ?download=1).
        'api_supply_attachment_inline' => ['supply', 'attachmentId'],
        'api_wh_document' => ['wh', 'id'],
        // S5: /api/plan/files/{type}/{id}/download|inline — файли задач, заявок і складу з «Плану».
        'api_plan_file_download' => [null, 'id'],
        'api_plan_file_inline' => [null, 'id'],
    ];

    public function __construct(
        private readonly PlanEventRecorder $events,
        private readonly AuditThrottle $throttle,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
        private readonly TasksSection $plan,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -64)]
    public function onResponse(ResponseEvent $event): void
    {
        // Журнал аудиту дивиться суперадмін «Плану»; стенди заявок (TASKS_ENABLED=0) скачувань не журналять.
        if (! $event->isMainRequest() || ! $event->getRequest()->isMethod('GET') || $event->getResponse()->getStatusCode() !== 200 || ! $this->plan->enabled()) {
            return;
        }

        $request = $event->getRequest();
        $route = self::ROUTES[(string) $request->attributes->get('_route')] ?? null;
        $user = $this->security->getUser();

        if ($route === null || ! $user instanceof TelegramUser) {
            return;
        }

        $fileType = $route[0] ?? (string) $request->attributes->get('type');
        $fileId = (int) $request->attributes->get($route[1], 0);

        try {
            $file = $this->resolve($fileType, $fileId);

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
     * @return array{0: SubjectRef, 1: int, 2: string}|null суб'єкт, id файлу, ім'я
     */
    private function resolve(string $fileType, int $id): ?array
    {
        switch ($fileType) {
            case 'task':
                $file = $this->em->find(TaskAttachment::class, $id);

                return $file !== null ? [SubjectRef::task((int) $file->getTask()->getId()), (int) $file->getId(), $file->getOriginalName()] : null;

            case 'supply':
                $file = $this->em->find(SupplyAttachment::class, $id);

                return $file !== null ? [SubjectRef::supply((int) $file->getRequest()->getId()), (int) $file->getId(), $file->getOriginalName()] : null;

            case 'wh':
                $file = $this->em->find(WhDocument::class, $id);
                $owner = $file?->getOwner();

                if ($file === null || $owner === null) {
                    return null;
                }

                return [SubjectRef::of(SubjectType::fromWarehouse(ActivityLog::typeOf($owner)), (int) $owner->getId()), (int) $file->getId(), $file->getOriginalName()];

            default:
                return null;
        }
    }
}
