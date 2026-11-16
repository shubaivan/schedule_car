<?php

namespace App\EventSubscriber;

use App\Entity\TelegramUser;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Repository\SupplyVisibility;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Будь-який контролер, що отримує заявку з URL (картка, стрічка, файли, статус,
 * коментар, закупівлі), — лише для тих, кому її видно (SupplyVisibility). Чужа заявка
 * відповідає 404, як неіснуюча: за посиланням не видно навіть, що вона є.
 */
final class SupplyRequestGate implements EventSubscriberInterface
{
    public function __construct(
        private readonly SupplyVisibility $visibility,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER_ARGUMENTS => 'onArguments'];
    }

    public function onArguments(ControllerArgumentsEvent $event): void
    {
        $user = $this->security->getUser();

        if (! $user instanceof TelegramUser) {
            return;
        }

        foreach ($event->getArguments() as $argument) {
            if ($argument instanceof SupplyRequest && ! $this->visibility->canView($argument, $user)) {
                throw new NotFoundHttpException('Заявку не знайдено.');
            }
        }
    }
}
