<?php

namespace App\Security;

use App\Controller\LoginController;
use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * Вхід на «робоче місце» за логіном (email або телефон) і паролем.
 *
 * Вмикається PASSWORD_LOGIN_ENABLED: так працюють люди, які сидять за
 * компʼютером і не користуються ботом. Посилання з бота лишається поруч.
 * Перебір паролів стримує login_throttling у security.yaml.
 */
class PasswordLoginAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private TelegramUserRepository $users,
        private EntityManagerInterface $em,
        private UrlGeneratorInterface $urlGenerator,
        private LoginController $login,
        #[Autowire('%password_login_enabled%')]
        private bool $enabled,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $this->enabled &&
            $request->isMethod('POST') &&
            $request->attributes->get('_route') === 'app_login_password';
    }

    public function authenticate(Request $request): Passport
    {
        $login = trim((string) $request->request->get('login'));
        $password = (string) $request->request->get('password');

        if ($login === '' || $password === '') {
            throw new CustomUserMessageAuthenticationException('Введіть логін і пароль.');
        }

        if ($request->hasSession()) {
            $request->getSession()->set(LoginController::LAST_LOGIN, mb_substr($login, 0, 180));
        }

        return new Passport(
            new UserBadge(mb_strtolower($login), function (string $identifier): TelegramUser {
                $user = $this->find($identifier);

                // Та сама відповідь, що й на хибний пароль: не підказуємо, чи є такий логін.
                if ($user === null || $user->isArchived() || ! $user->isApproved() || $user->getPassword() === null) {
                    throw new CustomUserMessageAuthenticationException('Невірний логін або пароль.');
                }

                return $user;
            }),
            new PasswordCredentials($password),
            [new CsrfTokenBadge('login', (string) $request->request->get('_csrf_token'))],
        );
    }

    public function onAuthenticationSuccess(Request $request, $token, string $firewallName): ?Response
    {
        $user = $token->getUser();

        if ($user instanceof TelegramUser) {
            $user->touchLastSeen();
            $this->em->flush();
        }

        $next = $request->hasSession() ? $request->getSession()->remove(LoginEntryPoint::TARGET) : null;

        return new RedirectResponse(\App\Service\CrmLoginLink::safeNext($next) ?? $this->urlGenerator->generate('crm_index'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = $exception instanceof TooManyLoginAttemptsAuthenticationException
            ? 'Забагато спроб. Зачекайте кілька хвилин.'
            : ($exception instanceof CustomUserMessageAuthenticationException ? $exception->getMessageKey() : 'Невірний логін або пароль.');

        return $this->login->page($message, status: Response::HTTP_UNAUTHORIZED);
    }

    private function find(string $identifier): ?TelegramUser
    {
        if (str_contains($identifier, '@')) {
            return $this->users->findOneBy(['email' => $identifier]);
        }

        $digits = preg_replace('/\D+/', '', $identifier) ?? '';

        return strlen($digits) >= 9 ? $this->users->findOneByPhoneTail(substr($digits, -9)) : null;
    }
}
