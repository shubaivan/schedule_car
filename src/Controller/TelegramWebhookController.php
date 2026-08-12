<?php

namespace App\Controller;

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
     * Порожній секрет (локальна розробка) вимикає перевірку.
     */
    #[Route('/hook', name: 'app_webhook')]
    public function hook(
        Nutgram $bot,
        Request $request,
        #[Autowire('%env(TELEGRAM_WEBHOOK_SECRET)%')] string $secret,
    ): Response {
        if ($secret !== '' && !hash_equals($secret, (string)$request->headers->get('X-Telegram-Bot-Api-Secret-Token'))) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $bot->run();

        return new Response();
    }
}
