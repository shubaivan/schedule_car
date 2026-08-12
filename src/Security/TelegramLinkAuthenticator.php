<?php

namespace App\Security;

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

        if (! $user->getSupplyRole()->canManage()) {
            throw new CustomUserMessageAuthenticationException('У вас немає доступу до CRM.');
        }

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), static fn () => $user),
        );
    }

    public function onAuthenticationSuccess(Request $request, $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->urlGenerator->generate('crm_index'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new Response(
            sprintf(
                '<!doctype html><meta charset="utf-8"><title>Вхід у CRM</title>'
                . '<p style="font:16px/1.5 system-ui;padding:2rem">%s</p>',
                htmlspecialchars($exception->getMessageKey(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ),
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
