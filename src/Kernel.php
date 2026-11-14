<?php

namespace App;

use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
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

    /**
     * Бот створюється лише тоді, коли ним справді користуються.
     *
     * Nutgram з порожнім TELEGRAM_TOKEN кидає виняток уже в конструкторі, а його
     * впроваджено в сповіщувачі, які тягне TaskService, — без цього стенд без бота
     * («План» до вибору бота) падав би 500 на кожному запиті до задач.
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(Nutgram::class)) {
            $container->getDefinition(Nutgram::class)->setLazy(true);
        }
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
