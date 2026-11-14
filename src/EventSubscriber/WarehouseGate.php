<?php

namespace App\EventSubscriber;

use App\Warehouse\Service\WarehouseSection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Вимкнений склад не існує і в адмінці: /sklad віддає 404, як будь-яка
 * неіснуюча сторінка. Каркас спільний на двох клієнтів, а склад потрібен одному.
 * Те саме — JSON API складу для «Плану» (/api/warehouse).
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

        if (! $this->section->isEnabled() && ($path === '/sklad' || str_starts_with($path, '/sklad/') || $path === '/api/warehouse' || str_starts_with($path, '/api/warehouse/'))) {
            throw new NotFoundHttpException();
        }

        // API складу приймає multipart і форми — те, що чужий сайт може надіслати звичайною
        // HTML-формою з cookie людини. Twig-склад для цього має CSRF-токен; тут — заголовок,
        // якого HTML-форма поставити не може (фронт «Плану» шле його з кожним записом).
        $request = $event->getRequest();

        if (($path === '/api/warehouse' || str_starts_with($path, '/api/warehouse/')) &&
            ! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true) &&
            $request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
            throw new AccessDeniedHttpException('Запит не з «Плану» — оновіть сторінку.');
        }
    }
}
