<?php

namespace App\Plan\Login;

use App\Entity\TelegramUser;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Вхід за паролем у «План» → повідомлення в Telegram людини (час, IP, браузер) і що
 * робити, якщо це не вона: /parol — новий пароль завершує всі інші входи. Надсилається
 * після відповіді (kernel.terminate), щоб вхід не чекав на Telegram.
 */
final class PasswordLoginNotice
{
    public function __construct(
        private readonly Nutgram $bot,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function loggedIn(TelegramUser $user, Request $request): void
    {
        $chat = $user->getChatId() ?: $user->getTelegramId();
        $text = sprintf(
            "🔐 Вхід у План за паролем\n%s · IP %s\n%s\n\nЯкщо це були не ви — одразу надішліть /parol і задайте новий пароль: усі інші входи завершаться.",
            new DateTimeImmutable()->format('d.m.Y H:i'),
            (string) $request->getClientIp(),
            mb_substr((string) $request->headers->get('User-Agent', 'браузер невідомий'), 0, 120),
        );

        $once = function (TerminateEvent $event) use ($chat, $text, $user, &$once): void {
            $this->dispatcher->removeListener(KernelEvents::TERMINATE, $once);

            try {
                $this->bot->sendMessage(text: $text, chat_id: $chat);
            } catch (Throwable $e) {
                $this->logger->warning('plan: не вдалося повідомити про вхід за паролем', ['user' => $user->getId(), 'error' => $e->getMessage()]);
            }
        };
        $this->dispatcher->addListener(KernelEvents::TERMINATE, $once);
    }
}
