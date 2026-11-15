<?php

namespace App\Plan\Login;

use App\Entity\TelegramUser;
use App\Plan\Event\StaffChanged;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Telegram\Types\User\User;
use Throwable;

/**
 * Telegram прив'язано до картки за номером телефону (перший вхід через бота): подія
 * StaffChanged (action telegram) у журналі аудиту + повідомлення суперадмінам — щоб
 * переданий іншій людині номер (стара SIM) не відкрив чужу картку непомітно.
 */
final class TelegramLinkAudit
{
    public function __construct(
        private readonly PlanEventRecorder $events,
        private readonly JoinNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function linkedByPhone(TelegramUser $user, ?User $from): void
    {
        try {
            $this->events->record(new StaffChanged(SubjectRef::user((int) $user->getId()), $user->getId(), action: 'telegram', fields: ['telegram']));
        } catch (Throwable $e) {
            $this->logger->warning('plan: прив\'язку Telegram не записано в журнал', ['error' => $e->getMessage()]);
        }

        $this->notifier->linked($user, $from?->username);
    }
}
