<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/**
 * Зміна статусу — одна дія дає рівно ОДИН рядок журналу, а його тип залежить
 * від того, куди перейшли: на погодження → ApprovalRequested, у закритий
 * статус → SubjectClosed, інакше → StatusChanged. Споживачі, яким усе одно
 * (сповіщення про зміну статусу), ловлять базовий клас.
 *
 * from — null лише для першого статусу (backfill створення заявки не сюди, а в SubjectCreated).
 * refId — supply_status_log.id / task_message.id системної події.
 */
abstract class StatusEvent extends PlanEvent
{
    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly ?string $from,
        public readonly string $to,
        public readonly ?string $comment = null,
        public readonly ?int $refId = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }

    /** Обрати клас події за переходом. */
    public static function for(
        SubjectRef $subject,
        ?int $actorId,
        ?string $from,
        string $to,
        bool $closes,
        bool $asksApproval,
        ?string $comment = null,
        ?int $refId = null,
        ?DateTimeImmutable $at = null,
    ): self {
        $comment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;

        return match (true) {
            $asksApproval => new ApprovalRequested($subject, $actorId, $from, $to, $comment, $refId, $at),
            $closes => new SubjectClosed($subject, $actorId, $from, $to, $comment, $refId, $at),
            default => new StatusChanged($subject, $actorId, $from, $to, $comment, $refId, $at),
        };
    }
}
