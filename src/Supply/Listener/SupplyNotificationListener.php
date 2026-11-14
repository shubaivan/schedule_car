<?php

namespace App\Supply\Listener;

use App\Plan\Event\CommentAdded;
use App\Plan\Event\FileAdded;
use App\Plan\Event\FileRemoved;
use App\Plan\Event\PlanEvent;
use App\Plan\Event\PurchaseRecorded;
use App\Plan\Event\PurchaseRemoved;
use App\Plan\Event\StatusEvent;
use App\Plan\Event\SubjectCreated;
use App\Plan\EventStore\PlanEventConsumer;
use App\Plan\Notification\TelegramGate;
use App\Plan\Subject\SubjectType;
use App\Supply\Entity\SupplyAttachment;
use App\Supply\Entity\SupplyComment;
use App\Supply\Entity\SupplyPurchase;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Entity\SupplyStatusLog;
use App\Supply\Service\SupplyNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Telegram по заявках — з подій журналу, а не прямими викликами із сервісів.
 *
 * Це ЄДИНЕ місце, звідки події заявки доходять до SupplyNotifier (страж —
 * NoDoubleNotificationTest): сервіс пише подію, слухач шле рівно одне
 * повідомлення. Тексти й адресати лишаються в SupplyNotifier.
 *
 * На стендах заявок (sync) викликається в тому ж запиті одразу після коміту —
 * поведінка та сама, що й до журналу; на Плані — у воркері.
 */
final class SupplyNotificationListener implements PlanEventConsumer
{
    public function __construct(
        private readonly SupplyNotifier $notifier,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly TelegramGate $gate,
    ) {
    }

    public function consume(PlanEvent $event): void
    {
        if ($event->subject->type !== SubjectType::Supply) {
            return;
        }

        $request = $this->em->find(SupplyRequest::class, $event->subject->id);

        if ($request === null) {
            $this->skip($event, 'заявки вже немає');

            return;
        }

        // Доставка в Telegram позначиться в notification.delivered_tg_at саме цієї події.
        $this->gate->during($event, fn () => match (true) {
            $event instanceof SubjectCreated => $this->notifier->requestCreated($request),
            $event instanceof StatusEvent => $this->status($event, $request),
            $event instanceof CommentAdded => $this->comment($event),
            $event instanceof PurchaseRecorded => $this->purchase($event),
            $event instanceof PurchaseRemoved => $this->notifier->purchaseRemoved($request, $event->supplier, $event->total),
            $event instanceof FileAdded => $this->file($event),
            $event instanceof FileRemoved => $this->notifier->fileRemoved($request, $event->names[0] ?? 'файл'),
            // Мітка заявки (SubjectUpdated) і решта — без Telegram, як і раніше.
            default => null,
        });
    }

    private function status(StatusEvent $event, SupplyRequest $request): void
    {
        $log = $event->refId !== null ? $this->em->find(SupplyStatusLog::class, $event->refId) : null;

        $log !== null ? $this->notifier->statusChanged($request, $log) : $this->skip($event, 'немає запису статусу');
    }

    private function comment(CommentAdded $event): void
    {
        $comment = $this->em->find(SupplyComment::class, $event->refId);

        $comment !== null ? $this->notifier->commentAdded($comment) : $this->skip($event, 'коментар видалено');
    }

    private function purchase(PurchaseRecorded $event): void
    {
        if (! $event->notify) {
            return;
        }

        $purchase = $this->em->find(SupplyPurchase::class, $event->refId);

        $purchase !== null ? $this->notifier->purchaseRecorded($purchase, $event->updated) : $this->skip($event, 'закупівлю вже скасовано');
    }

    private function file(FileAdded $event): void
    {
        if (! $event->notify) {
            return;
        }

        foreach ($event->fileIds as $id) {
            $attachment = $this->em->find(SupplyAttachment::class, $id);

            $attachment !== null ? $this->notifier->fileAttached($attachment) : $this->skip($event, 'файл уже прибрано');
        }
    }

    private function skip(PlanEvent $event, string $why): void
    {
        $this->logger->info('supply: сповіщення пропущено — ' . $why, ['event' => $event->id(), 'type' => $event::type()]);
    }
}
