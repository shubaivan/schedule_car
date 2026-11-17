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
 * Системний адміністратор (Олександр 17.11) не бачить задач і «Звʼязку» — закрито й на рівні
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

        $path = $event->getRequest()->getPathInfo();
        $tasks = self::matches($path, self::TASKS) && ! in_array(rtrim($path, '/'), self::OPEN, true);
        $chats = self::matches($path, self::CHATS);

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
