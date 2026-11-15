<?php

namespace App\Security;

use App\Plan\Login\TelegramLogin;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * «🚫 Це був не я» у боті: сесію, яку відкрила відкликана спроба входу через Telegram,
 * завершуємо на першому ж запиті (сторінка → /vhid, API → 401). Перевіряємо не частіше
 * ніж раз на CHECK_EVERY секунд і лише в сесіях, відкритих через Telegram, — один
 * запит за первинним ключем.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class TelegramSessionGuard
{
    public const int CHECK_EVERY = 15;
    private const string CHECKED = '_plan_tg_checked';

    public function __construct(
        private readonly TelegramLogin $login,
        private readonly TokenStorageInterface $tokens,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (! $event->isMainRequest() || ! $request->hasPreviousSession() || $this->tokens->getToken() === null) {
            return;
        }

        $session = $request->getSession();
        $attempt = $session->get(TelegramLogin::SESSION_OPENED);

        if (! is_int($attempt) || $attempt <= 0 || time() - (int) $session->get(self::CHECKED, 0) < self::CHECK_EVERY) {
            return;
        }

        if (! $this->login->isRevoked($attempt)) {
            $session->set(self::CHECKED, time());

            return;
        }

        $this->tokens->setToken(null);
        $session->invalidate();

        $event->setResponse(str_starts_with($request->getPathInfo(), '/api')
            ? new JsonResponse(['error' => 'Сесію завершено з Telegram («Це був не я»).'], Response::HTTP_UNAUTHORIZED)
            : new RedirectResponse('/vhid?revoked=1'));
    }
}
