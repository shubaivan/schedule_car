<?php

namespace App\EventSubscriber;

use App\Entity\TelegramUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Роль, якій на «Ролі й доступ» вимкнено «Задачі й угоди» чи «Звʼязок», не бачить їх — закрито й на рівні
 * URL, а не лише в меню й у сервісах (TaskPolicy, TaskVisibility, пошук, Хроніка, сповіщення):
 * API задач, файлів задач і чатів відповідає 404, ніби їх немає.
 *
 * Відкрито: /api/tasks/meta — довідники (люди, відділи, ролі) для сторінок «Люди» й «Ролі».
 */
final class PlanTasksGate implements EventSubscriberInterface
{
    private const array TASKS = ['/api/tasks', '/api/plan/files/task'];
    private const array CHATS = ['/api/plan/zvyazok'];
    private const array OPEN = ['/api/tasks/meta'];

    public function __construct(private readonly Security $security)
    {
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

        $request = $event->getRequest();
        // Шлях — сирий (`/api/%74asks` його обходив би), тож головне — імʼя маршруту, яке
        // RouterListener (пріоритет 32) уже визначив після декодування; шлях — друга лінія.
        $route = (string) $request->attributes->get('_route', '');
        $path = rawurldecode($request->getPathInfo());
        $tasks = (str_starts_with($route, 'api_tasks_') && $route !== 'api_tasks_meta') ||
            (str_starts_with($route, 'api_plan_file') && $request->attributes->get('type') === 'task') ||
            (self::matches($path, self::TASKS) && ! in_array(rtrim($path, '/'), self::OPEN, true));
        $chats = str_starts_with($route, 'api_plan_zv_') || self::matches($path, self::CHATS);

        if (! $tasks && ! $chats) {
            return;
        }

        $user = $this->security->getUser();

        if (! $user instanceof TelegramUser) {
            return;
        }

        if (($tasks && ! $user->canSeeTasks()) || ($chats && ! $user->canUseZvyazok())) {
            $event->setResponse(new JsonResponse(['error' => 'Не знайдено.'], Response::HTTP_NOT_FOUND));
        }
    }

    /** @param list<string> $prefixes */
    private static function matches(string $path, array $prefixes): bool
    {
        return array_any($prefixes, static fn (string $p) => $path === $p || str_starts_with($path, $p . '/'));
    }
}
