<?php

namespace App\EventSubscriber;

use App\Warehouse\Service\WarehouseSection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Вимкнений склад не існує і в адмінці: /sklad віддає 404, як будь-яка
 * неіснуюча сторінка. Каркас спільний на двох клієнтів, а склад потрібен одному.
 */
class WarehouseGate implements EventSubscriberInterface
{
    public function __construct(
        private WarehouseSection $section,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Раніше за файрвол (8): інакше вимкнений розділ відповідав би редиректом на вхід.
        return [KernelEvents::REQUEST => ['onRequest', 16]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();

        if (! $this->section->isEnabled() && ($path === '/sklad' || str_starts_with($path, '/sklad/'))) {
            throw new NotFoundHttpException();
        }
    }
}
