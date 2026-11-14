<?php

namespace App\Supply\Service;

use App\Entity\TelegramUser;
use App\Plan\Event\SubjectUpdated;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyAccent;
use App\Supply\Exception\SupplyException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Мітка заявки для керівника. Одна точка правди для бота і CRM:
 * ставить її лише той, хто веде заявки, — менеджер із постачання.
 */
class MarkRequest
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private PlanEventRecorder $events,
    ) {
    }

    public function __invoke(SupplyRequest $request, SupplyAccent $accent, TelegramUser $by): void
    {
        if (! $by->getSupplyRole()->canManage()) {
            throw new SupplyException('Мітку для керівника ставить менеджер із постачання.');
        }

        if ($request->getAccent() === $accent) {
            return;
        }

        $request->setAccent($accent);

        $this->events->transactional(function () use ($request, $accent, $by): void {
            $this->em->flush();
            $this->events->record(new SubjectUpdated(
                SubjectRef::supply((int) $request->getId()),
                $by->getId(),
                fields: ['accent'],
                summary: $accent->value,
            ));
        });

        $this->logger->info('supply: мітка заявки', [
            'request' => $request->getNumber(),
            'accent' => $accent->value,
            'by' => $by->displayName(),
        ]);
    }
}
