<?php

namespace App\Tests\Telegram;

use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /hook — fail closed: бот увімкнено, а секрету вебхука немає — нічого не
 * обробляємо (а не приймаємо підроблені апдейти від будь-кого).
 */
final class WebhookSecretTest extends WebTestCase
{
    /** @var array<string, array{0: mixed, 1: mixed}> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$server, $env]) {
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }

            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
        }

        $this->saved = [];
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, array<string, string>, int}> */
    public static function cases(): iterable
    {
        yield 'бота немає — вебхука немає' => ['', '', [], 404];
        yield 'бот є, секрету немає — 403' => ['123:token', '', [], 403];
        yield 'бот є, секрету немає, хтось шле будь-що в заголовку — 403' => ['123:token', '', ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => ''], 403];
        yield 'хибний секрет — 403' => ['123:token', 'right-secret', ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'wrong'], 403];
    }

    /**
     * @param array<string, string> $headers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testHookRequiresTheSecretWhenTheBotIsOn(string $token, string $secret, array $headers, int $status): void
    {
        $this->env('TELEGRAM_TOKEN', $token);
        $this->env('TELEGRAM_WEBHOOK_SECRET', $secret);
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->set(Nutgram::class, Nutgram::fake());

        $client->request('POST', '/hook', server: ['CONTENT_TYPE' => 'application/json', ...$headers], content: '{"update_id":1}');

        self::assertResponseStatusCodeSame($status);
    }

    private function env(string $name, string $value): void
    {
        $this->saved[$name] ??= [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
        $_SERVER[$name] = $_ENV[$name] = $value;
    }
}
