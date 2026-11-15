<?php

namespace App\Security;

use App\Controller\LoginController;
use App\Entity\TelegramUser;
use App\Plan\Login\TelegramLogin;
use App\Plan\Login\TelegramLoginController;
use App\Service\CrmLoginLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Вхід через Telegram, останній крок: браузер, чию спробу бот уже підтвердив,
 * POST-ом (з CSRF, перевіреним до того, як спробу витрачено) забирає її — TelegramLogin::complete() міняє confirmed → used рівно
 * один раз і повертає людину. Сесія після входу пам'ятає id спроби (TelegramLogin::SESSION_OPENED),
 * щоб «🚫 Це був не я» у боті могло її завершити (TelegramSessionGuard).
 */
final class TelegramLoginAuthenticator extends AbstractAuthenticator
{
    private const string ATTEMPT = '_plan_tg_attempt';

    public function __construct(
        private readonly TelegramLogin $login,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoginController $page,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && $request->attributes->get('_route') === 'app_login_telegram_complete';
    }

    public function authenticate(Request $request): Passport
    {
        // CSRF — до complete(): інакше чужа форма «спалила» б підтверджену спробу.
        if (! $this->csrf->isTokenValid(new CsrfToken(TelegramLoginController::CSRF_ID, (string) $request->request->get('_csrf_token')))) {
            throw new CustomUserMessageAuthenticationException('Сторінка входу застаріла. Оновіть її й спробуйте ще раз.');
        }

        $done = $request->hasSession() ? $this->login->complete($request->getSession()) : null;

        if ($done === null) {
            throw new CustomUserMessageAuthenticationException('Вхід не підтверджено або час минув. Натисніть «Увійти через Telegram» ще раз.');
        }

        [$user, $attempt] = $done;
        $request->attributes->set(self::ATTEMPT, $attempt);

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), static fn () => $user),
        );
    }

    public function onAuthenticationSuccess(Request $request, $token, string $firewallName): Response
    {
        $user = $token->getUser();

        if ($user instanceof TelegramUser) {
            $user->touchLastSeen();
            $this->em->flush();
        }

        $session = $request->getSession();
        $session->set(TelegramLogin::SESSION_OPENED, (int) $request->attributes->get(self::ATTEMPT));
        $next = CrmLoginLink::safeNext($session->remove(LoginEntryPoint::TARGET));

        return new RedirectResponse($next ?? $this->urlGenerator->generate('crm_index'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $message = $exception instanceof TooManyLoginAttemptsAuthenticationException
            ? 'Забагато спроб. Зачекайте кілька хвилин.'
            : ($exception instanceof CustomUserMessageAuthenticationException ? $exception->getMessageKey() : 'Вхід не вдався. Спробуйте ще раз.');

        return $this->page->page($message, status: Response::HTTP_UNAUTHORIZED);
    }
}
