<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TelegramWebhookController extends AbstractController
{
    /**
     * Вебхук відкритий назовні, а серед колбеків — підтвердження доступу до бота,
     * тож перевіряємо секрет, який Telegram надсилає заголовком.
     *
     * Fail closed: бот увімкнено (TELEGRAM_TOKEN задано), а TELEGRAM_WEBHOOK_SECRET
     * порожній — /hook нічого не обробляє (403 + помилка в лозі), а не приймає
     * будь-який POST. Задавати ОБИДВА (plan:bot:webhook ставить секрет у Telegram).
     * Без бота (токен порожній) вебхука просто немає — 404.
     */
    #[Route('/hook', name: 'app_webhook')]
    public function hook(
        Nutgram $bot,
        Request $request,
        #[Autowire('%env(TELEGRAM_WEBHOOK_SECRET)%')]
        string $secret,
        #[Autowire('%env(string:TELEGRAM_TOKEN)%')]
        string $token,
        LoggerInterface $logger,
    ): Response {
        // Стенд без бота (TELEGRAM_TOKEN порожній): вебхука просто нема.
        if ($token === '') {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if ($secret === '') {
            $logger->error('telegram: /hook відхилено — TELEGRAM_WEBHOOK_SECRET порожній при заданому TELEGRAM_TOKEN');

            return new Response('', Response::HTTP_FORBIDDEN);
        }

        if (! hash_equals($secret, (string) $request->headers->get('X-Telegram-Bot-Api-Secret-Token'))) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $bot->run();

        return new Response();
    }
}
