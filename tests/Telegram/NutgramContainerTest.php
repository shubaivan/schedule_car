<?php

namespace App\Tests\Telegram;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\Fake;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The bot from the container must survive its own lazy initialisation.
 *
 * 14.11 on «План» with a real token: plan:bot:webhook, bot:menu and /hook all
 * died with "Nutgram::$container must not be accessed before initialization" —
 * the bundle's configurator loaded config/telegram.php into the lazy proxy while
 * that proxy was still being initialised. Without a token nobody touched the bot,
 * so no test saw it.
 */
class NutgramContainerTest extends KernelTestCase
{
    public function testContainerBotLoadsRoutesIntoTheRealInstance(): void
    {
        self::bootKernel();

        $bot = self::getContainer()->get(Nutgram::class);
        // Test env: the bundle builds FakeNutgram; config/telegram.php must have been loaded into it.
        self::assertInstanceOf(FakeNutgram::class, $bot);

        $bot->setRunningMode(new Fake());
        $bot->hearText('/nemaje-takoji-komandy')
            ->reply()
            ->assertNoReply();
    }
}
