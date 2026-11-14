<?php

namespace App\Warehouse\Service;

use App\Entity\TelegramUser;
use App\Plan\Audit\AuditThrottle;
use App\Plan\Event\FileAdded;
use App\Plan\Event\FileRemoved;
use App\Plan\Event\ItemCreated;
use App\Plan\Event\PlanEvent;
use App\Plan\Event\SubjectCreated;
use App\Plan\Event\SubjectUpdated;
use App\Plan\Event\SubjectViewed;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Entity\WhCategory;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\ActivityAction;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Запис у журнал складу.
 *
 * Ніколи не фатальний: журнал — це вимірювання, а людина прийшла по картку.
 * Зламаний запис у журнал не має коштувати їй ні скану, ні збереження.
 *
 * Пише в обидва журнали: wh_activity (Twig-журнал складу) і plan_event (аудит,
 * Хроніка «Плану»). Рух (Move) у plan_event не дублюється — подію StockMoved
 * уже записав RecordMovement.
 */
class ActivityLog
{
    public const TYPES = [
        'item' => 'Майно',
        'client' => 'Клієнт',
        'site' => "Об'єкт",
        'movement' => 'Рух',
        'category' => 'Категорія',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private PlanEventRecorder $events,
        private AuditThrottle $throttle,
    ) {
    }

    public function record(
        ActivityAction $action,
        WhItem|WhClient|WhSite|WhMovement|WhCategory $subject,
        ?TelegramUser $user,
        string $channel,
        ?string $details = null,
    ): void {
        try {
            $activity = new WhActivity(
                $action,
                self::typeOf($subject),
                (int) $subject->getId(),
                self::labelOf($subject),
                $user,
                $channel,
                $details,
            );
            $this->em->persist($activity);

            // Savepoint: збій журналу не отруює зовнішню транзакцію (PostgreSQL).
            $this->events->transactional(function () use ($activity, $action, $subject, $user, $channel, $details): void {
                $this->em->flush();
                $event = self::event($action, $subject, $user, $details, (int) $activity->getId());

                // Відкриття картки в адмінці — не частіше одного рядка аудиту на людину й картку
                // за 10 хв (AuditThrottle); скан, перехід із бота й друк наклейок — кожен.
                if ($event instanceof SubjectViewed && $action === ActivityAction::View &&
                    $this->throttle->seenRecently(SubjectViewed::TYPE, $event->actorId, $event->subject, ['action' => ActivityAction::View->value])) {
                    return;
                }

                if ($event !== null) {
                    $this->events->record($event->via($channel === WhActivity::CHANNEL_BOT ? 'bot' : 'web'));
                }
            });
        } catch (Throwable $e) {
            $this->logger->warning('warehouse: запис у журнал не вдався', [
                'action' => $action->value,
                'subject' => self::typeOf($subject) . '#' . $subject->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Подія «Плану» для запису журналу складу; null — подію вже пише інший сервіс. */
    public static function event(
        ActivityAction $action,
        WhItem|WhClient|WhSite|WhMovement|WhCategory $subject,
        ?TelegramUser $user,
        ?string $details,
        ?int $activityId,
    ): ?PlanEvent {
        $ref = SubjectRef::of(SubjectType::fromWarehouse(self::typeOf($subject)), (int) $subject->getId());
        $actor = $user?->getId();

        return match ($action) {
            ActivityAction::Create => $subject instanceof WhItem
                ? new ItemCreated($ref, $actor, title: self::labelOf($subject), refId: $activityId)
                : new SubjectCreated($ref, $actor, title: self::labelOf($subject), refId: $activityId),
            ActivityAction::Update => new SubjectUpdated($ref, $actor, summary: $details, refId: $activityId),
            ActivityAction::Move => null,
            ActivityAction::DocumentAdd => new FileAdded($ref, $actor, names: [(string) $details], refId: $activityId),
            ActivityAction::DocumentRemove => new FileRemoved($ref, $actor, names: [(string) $details]),
            ActivityAction::Scan, ActivityAction::Link, ActivityAction::View, ActivityAction::Labels => new SubjectViewed($ref, $actor, action: $action->value, details: $details, refId: $activityId),
        };
    }

    public static function typeOf(WhItem|WhClient|WhSite|WhMovement|WhCategory $subject): string
    {
        return match (true) {
            $subject instanceof WhItem => 'item',
            $subject instanceof WhClient => 'client',
            $subject instanceof WhSite => 'site',
            $subject instanceof WhMovement => 'movement',
            default => 'category',
        };
    }

    public static function labelOf(WhItem|WhClient|WhSite|WhMovement|WhCategory $subject): string
    {
        return match (true) {
            $subject instanceof WhItem => $subject->getLabel(),
            $subject instanceof WhClient => $subject->getName(),
            $subject instanceof WhSite => $subject->getLabel(),
            $subject instanceof WhMovement => $subject->getTitle(),
            default => $subject->getLabel(),
        };
    }
}
