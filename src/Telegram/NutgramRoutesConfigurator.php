<?php

namespace App\Telegram;

use SergiX44\Nutgram\Nutgram;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Loads config/telegram.php into the bot instance the container is building.
 *
 * Replaces the bundle's NutgramConfigurator: that one receives Nutgram in its
 * constructor, and since Nutgram is a lazy service (see Kernel::process()) it
 * gets the not-yet-initialised proxy — the route file then called methods on the
 * proxy from inside its own initializer and every real use of the bot (webhook,
 * bot:menu, notifications) failed with "Nutgram::$container must not be
 * accessed before initialization". A configurator callable gets the real
 * instance as its argument, so this one never touches the proxy.
 */
final readonly class NutgramRoutesConfigurator
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        #[Autowire('%nutgram.config%')]
        private array $config,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function configure(Nutgram $bot): void
    {
        if (! ($this->config['routes'] ?? false)) {
            return;
        }

        $file = $this->projectDir . '/config/telegram.php';
        if (! is_file($file)) {
            return;
        }

        (static function (Nutgram $bot, string $file): void {
            require $file;
        })($bot, $file);
    }
}
