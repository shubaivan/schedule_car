<?php

namespace App\EventSubscriber;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanSections;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Приховані розділи «Плану» (PlanSections) закриті й на рівні URL, а не лише в меню:
 * без повного доступу API й сторінки Постачання, Складу, Контрагентів, Автопарку,
 * Google Диска відповідають 403. Довідники заявок (/api/supply/meta) лишаються —
 * їх читає старт CRM для всіх.
 *
 * Друга лінія після PlanAccess / SupplyVisibility: навіть якщо якийсь контролер
 * перевіряє лише роль, людина без повного доступу до розділу не дістанеться.
 */
final class PlanHiddenSectionGate implements EventSubscriberInterface
{
    /** Префікси шляхів прихованих розділів. */
    private const array HIDDEN = [
        '/api/supply/',
        '/api/warehouse',
        '/sklad',
        '/api/fleet',
        '/api/plan/drive',
        '/api/plan/counterparties',
        '/api/plan/zvyazok',
    ];

    /** Виняток у прихованих префіксах: довідники статусів і відділів — для старту CRM. */
    private const array OPEN = ['/api/supply/meta'];

    public function __construct(
        private readonly PlanSections $sections,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Після файрволу (8): користувач уже відомий.
        return [KernelEvents::REQUEST => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        if (! self::isHidden($path)) {
            return;
        }

        $user = $this->security->getUser();

        // Без входу відповідає файрвол (401 / редирект на вхід) — тут лише ті, хто увійшов.
        if (! $user instanceof TelegramUser || $this->sections->full($user)) {
            return;
        }

        $event->setResponse(str_starts_with($path, '/api/')
            ? new JsonResponse(['error' => 'Цей розділ поки відкритий не всім.', 'code' => 'section_hidden'], Response::HTTP_FORBIDDEN)
            : new Response('Цей розділ поки відкритий не всім.', Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain; charset=UTF-8']));
    }

    public static function isHidden(string $path): bool
    {
        if (in_array(rtrim($path, '/'), self::OPEN, true)) {
            return false;
        }

        return array_any(self::HIDDEN, fn ($prefix) => $path === rtrim($prefix, '/') || str_starts_with($path, rtrim($prefix, '/') . '/'));
    }
}
