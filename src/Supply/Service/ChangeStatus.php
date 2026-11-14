<?php

namespace App\Supply\Service;

use App\Entity\TelegramUser;
use App\Plan\Event\StatusEvent;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Entity\SupplyStatusLog;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Exception\SupplyException;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Зміна статусу заявки: перевірка переходу, аудит і подія для сповіщення
 * заявника — усе в одному місці, щоб жоден етап життєвого циклу не пройшов
 * повз автора (Telegram шле SupplyNotificationListener).
 */
class ChangeStatus
{
    public function __construct(
        private EntityManagerInterface $em,
        private PlanEventRecorder $events,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        SupplyRequest $request,
        SupplyStatus $to,
        ?TelegramUser $by = null,
        ?string $comment = null,
    ): SupplyRequest {
        $from = $request->getStatus();

        if ($from === $to) {
            throw new SupplyException(sprintf('Заявка вже має статус «%s».', $to->label()));
        }

        if (! $from->canTransitionTo($to)) {
            throw new SupplyException(sprintf(
                'Не можна перевести заявку з «%s» у «%s».',
                $from->label(),
                $to->label(),
            ));
        }

        if ($by !== null && ! $by->getSupplyRole()->canMoveRequest($from, $to)) {
            throw new SupplyException(
                in_array($from, [SupplyStatus::Approval, SupplyStatus::Waiting], true)
                    ? sprintf('Рішення про оплату ухвалює директор — заявку з «%s» рухає лише він.', $from->label())
                    : 'Змінювати статус заявки може лише менеджер із постачання.',
            );
        }

        $comment = $comment !== null ? trim($comment) : null;

        if ($to === SupplyStatus::Rejected && ($comment === null || $comment === '')) {
            throw new SupplyException('Вкажіть причину відхилення — інакше заявник не зрозуміє, що робити далі.');
        }

        if ($to->requiresPurchase() && ! $request->isPurchased()) {
            throw new SupplyException(sprintf(
                'Спершу вкажіть, у кого купили: без постачальника й суми заявку не можна перевести в «%s».',
                $to->label(),
            ));
        }

        $request->setStatus($to);
        $request->setClosedAt($to->isFinal() || $to === SupplyStatus::Rejected
            ? new DateTime('now', new DateTimeZone('Europe/Kyiv'))
            : null);

        $log = (new SupplyStatusLog())
            ->setStatusFrom($from)
            ->setStatusTo($to)
            ->setAuthor($by)
            ->setComment($comment);

        $request->addStatusLog($log);

        $this->em->persist($log);

        $this->events->transactional(function () use ($request, $log, $from, $to, $by, $comment): void {
            $this->em->flush();

            // Одна зміна — один рядок журналу; тип за переходом (погодження / закриття / решта).
            $this->events->record(StatusEvent::for(
                SubjectRef::supply((int) $request->getId()),
                $by?->getId(),
                $from->value,
                $to->value,
                closes: $to->isFinal() || $to === SupplyStatus::Rejected,
                asksApproval: $to === SupplyStatus::Approval,
                comment: $comment,
                refId: $log->getId(),
            ));
        });

        $this->logger->info('supply: зміна статусу заявки', [
            'number' => $request->getNumber(),
            'from' => $from->value,
            'to' => $to->value,
            'by' => $by?->displayName(),
        ]);

        return $request;
    }
}
