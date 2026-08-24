<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Вимикач розділу «Автопарк» у боті.
 *
 * Постачання й автопарк запускаються не разом: поки в парку немає машин і
 * водіїв, розділ тільки збиває працівників з пантелику. Вимикач ховає його з
 * меню й закриває всі кнопки, а дані лишаються на місці — повернути можна
 * однією змінною оточення, нічого не переносячи.
 */
class FleetSection
{
    public function __construct(
        #[Autowire('%fleet_enabled%')]
        private bool $enabled = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
