<?php

namespace App\EventSubscriber;

use App\Tasks\Service\TasksSection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Стенд заявок (TASKS_ENABLED=0) «Плану» не має зовсім: увесь /api/plan/*
 * (сповіщення, realtime, пошук, Хроніка, файли, аудит, Диск) — 404, як
 * TasksController::me(). Раніше аудит і статус Диска перевіряли лише права
 * (суперадмін / адмін заявок) і на стенді були б досяжні.
 */
final class PlanSectionGate implements EventSubscriberInterface
{
    public function __construct(private readonly TasksSection $section)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Раніше за файрвол (8): вимкнений розділ — 404 і без входу, а не редирект/403.
        return [KernelEvents::REQUEST => ['onRequest', 16]];
    }

    public function onRequest(RequestEvent $event): void
    {
        // Лише головний запит: під-запит сторінки помилки має той самий шлях і не повинен падати вдруге.
        if (! $event->isMainRequest() || $this->section->enabled()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        if ($path === '/api/plan' || str_starts_with($path, '/api/plan/')) {
            throw new NotFoundHttpException();
        }
    }
}
