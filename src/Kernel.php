<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Увесь застосунок працює в київському часі.
     *
     * Дати зберігаються в колонках TIMESTAMP WITHOUT TIME ZONE як київський
     * настінний час, і без цього рядка Doctrine читає їх назад як UTC: щойно
     * створений об'єкт і той самий об'єкт із бази показували різний час.
     */
    public function boot(): void
    {
        date_default_timezone_set('Europe/Kyiv');

        parent::boot();
    }
}
