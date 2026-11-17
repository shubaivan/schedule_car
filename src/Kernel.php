<?php

namespace App;

use App\Plan\Access\RoleGrants;
use App\Telegram\NutgramRoutesConfigurator;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait {
        registerContainerConfiguration as private registerMicroKernelConfiguration;
    }

    /**
     * Після config/services.yaml — config/services/*.yaml (по файлу на потік «Плану»):
     * вони уточнюють визначення, які `App\:` у services.yaml уже зареєстрував.
     */
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $this->registerMicroKernelConfiguration($loader);
        $loader->load($this->getProjectDir() . '/config/services/*.yaml', 'glob');
    }

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

        // Права ролей (RoleGrants) — статичний кеш: новий kernel (тести, воркер) починає з нуля,
        // таблицю підключить RoleGrantsReset на першому ж запиті / команді.
        if (! $this->booted) {
            RoleGrants::forget();
        }

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
            // The bundle's configurator would get the lazy proxy itself and load the
            // route file into it mid-initialisation — ours receives the real instance.
            // In the test env the bundle returns FakeNutgram, which a Nutgram lazy proxy
            // cannot wrap — and the fake never throws on an empty token anyway.
            $container->getDefinition(Nutgram::class)
                ->setLazy($this->environment !== 'test')
                ->setConfigurator([new Reference(NutgramRoutesConfigurator::class), 'configure']);
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
