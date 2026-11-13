<?php

namespace App\Tasks\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Вимикач розділу «Задачі» (TASKS_ENABLED): на стендах, де його не замовляли, розділу немає. */
class TasksSection
{
    public function __construct(
        #[Autowire('%tasks_enabled%')]
        private bool $enabled,
        #[Autowire('%env(default::CRM_HOME)%')]
        private ?string $home = null,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** З якого розділу відкривається CRM. */
    public function home(): string
    {
        return $this->enabled && $this->home === 'tasks' ? 'tasks' : 'requests';
    }
}
