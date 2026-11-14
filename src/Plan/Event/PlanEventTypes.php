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
        LeadReceived::class,
    ];

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
