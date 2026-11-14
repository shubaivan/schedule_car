<?php

namespace App\Plan\Event;

use InvalidArgumentException;

/**
 * Реєстр типів: рядок plan_event.type → клас. Новий тип події — сюди ж
 * (PlanEventTypesTest не дасть забути).
 */
final class PlanEventTypes
{
    /** @var list<class-string<PlanEvent>> */
    public const array CLASSES = [
        SubjectCreated::class,
        SubjectUpdated::class,
        StatusChanged::class,
        SubjectClosed::class,
        ApprovalRequested::class,
        CommentAdded::class,
        CommentEdited::class,
        CommentDeleted::class,
        MentionAdded::class,
        MemberAdded::class,
        MemberRemoved::class,
        FileAdded::class,
        FileRemoved::class,
        PurchaseRecorded::class,
        PurchaseRemoved::class,
        StockMoved::class,
        ItemCreated::class,
        SubjectViewed::class,
        UserLoggedIn::class,
        LoginFailed::class,
        FileDownloaded::class,
        ShareCreated::class,
        ShareRevoked::class,
        DriveAuthBroken::class,
        LeadReceived::class,
        StaffChanged::class,
    ];

    /**
     * Події лише для журналу аудиту (доступ, службове, зміни карток людей): у Хроніку й пошук не
     * потрапляють. Сповіщення з них — лише там, де є група (EventGroup::forEvent:
     * «поділились з людьми», «Диск відкликав доступ»). Новий «службовий» тип — сюди.
     *
     * @var list<class-string<PlanEvent>>
     */
    public const array AUDIT_ONLY = [
        SubjectViewed::class,
        UserLoggedIn::class,
        LoginFailed::class,
        FileDownloaded::class,
        ShareCreated::class,
        ShareRevoked::class,
        DriveAuthBroken::class,
        StaffChanged::class,
    ];

    public static function isAuditOnly(PlanEvent $event): bool
    {
        return in_array($event::class, self::AUDIT_ONLY, true);
    }

    /** @return class-string<PlanEvent> */
    public static function classOf(string $type): string
    {
        foreach (self::CLASSES as $class) {
            if ($class::type() === $type) {
                return $class;
            }
        }

        throw new InvalidArgumentException(sprintf('Unknown plan event type "%s"', $type));
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_map(static fn (string $class) => $class::type(), self::CLASSES);
    }
}
