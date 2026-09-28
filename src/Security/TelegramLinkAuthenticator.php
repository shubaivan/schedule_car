<?php

namespace App\Security;

use App\Controller\LoginController;
use App\Service\CrmLoginLink;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Вхід у CRM за одноразовим посиланням із бота: паролів у системі немає.
 */
class TelegramLinkAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private CrmLoginLink $loginLink,
        private UrlGeneratorInterface $urlGenerator,
        private LoginController $login,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'crm_auth';
    }

    public function authenticate(Request $request): Passport
    {
        // Краулер превʼю Telegram відкриває посилання раніше за людину. Якщо дати
        // йому спалити токен, користувач отримає «посилання вже використане».
        if (str_contains((string) $request->headers->get('User-Agent'), 'TelegramBot')) {
            throw new CustomUserMessageAuthenticationException('Посилання відкриється у браузері.');
        }

        $user = $this->loginLink->consume((string) $request->attributes->get('token'));

        if ($user === null) {
            throw new CustomUserMessageAuthenticationException(
                'Посилання застаріло або вже використане. Надішліть нове через бота.',
            );
        }

        // Ролі тут не питаємо: у CRM заходять усі підтверджені, щоб бачити
        // спільну картину заявок. Кого пускати взагалі, вирішує видача
        // посилання — бот дає кнопку лише тому, кого підтвердив менеджер
        // (middleware RequireApproval), а решта її просто не бачить.
        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), static fn () => $user),
        );
    }

    public function onAuthenticationSuccess(Request $request, $token, string $firewallName): ?Response
    {
        // Посилання з картки складу веде одразу на цю картку, а не на заявки.
        // Інакше — туди, куди людина йшла до входу (запам'ятав LoginEntryPoint).
        $next = CrmLoginLink::safeNext($request->query->get('next'))
            ?? CrmLoginLink::safeNext($request->hasSession() ? $request->getSession()->remove(LoginEntryPoint::TARGET) : null);

        return new RedirectResponse($next ?? $this->urlGenerator->generate('crm_index'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Та сама сторінка входу, що й для закритих сторінок: з причиною і тим,
        // де взяти нове посилання. 401 лишається — посилання справді не спрацювало.
        return $this->login->page($exception->getMessageKey(), status: Response::HTTP_UNAUTHORIZED);
    }
}
