<?php

namespace App\Security;

use App\Service\CrmLoginLink;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Куди відправляти неавторизованого.
 *
 * API має відповідати 401 — Vue-застосунок за цим кодом показує «сесія завершилась».
 * Людині ж показуємо сторінку входу з поясненням, де взяти посилання, а не сторінку помилки.
 */
class LoginEntryPoint implements AuthenticationEntryPointInterface
{
    public const TARGET = 'login_target';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (str_starts_with($request->getPathInfo(), '/api')) {
            return new JsonResponse(['error' => 'Потрібна авторизація'], Response::HTTP_UNAUTHORIZED);
        }

        // Куди людина йшла — туди й поведемо після входу, а не на загальну сторінку.
        $target = CrmLoginLink::safeNext($request->getPathInfo());

        if ($target !== null && $request->hasSession()) {
            $request->getSession()->set(self::TARGET, $target);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
