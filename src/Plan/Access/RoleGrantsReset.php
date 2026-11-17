<?php

namespace App\Plan\Access;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Права ролей (RoleGrants) читаються з таблиці раз на запит / команду / повідомлення воркера:
 * адмін змінив право — наступний запит і наступна подія у воркері вже бачать нове.
 */
final readonly class RoleGrantsReset
{
    public function __construct(private RolePermissions $permissions)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
    #[AsEventListener(event: ConsoleEvents::COMMAND, priority: 4096)]
    #[AsEventListener(event: WorkerMessageReceivedEvent::class, priority: 4096)]
    public function __invoke(object $event): void
    {
        $this->permissions->register();
    }
}
