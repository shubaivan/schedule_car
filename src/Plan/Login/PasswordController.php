<?php

namespace App\Plan\Login;

use App\Controller\LoginController;
use App\Entity\TelegramUser;
use App\Plan\People\PhoneFormat;
use App\Security\LoginIdentity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Пароль для входу в «План» (PasswordSetup) — сайтова частина.
 *
 *   GET|POST /vhid/parol          — «Забули пароль / ще не маєте?»: телефон → посилання в Telegram
 *                                    (відповідь завжди однакова — не підказуємо, чи є номер);
 *   GET      /vhid/parol/{token}  — форма нового пароля (GET нічого не витрачає: прев'ю Telegram);
 *   POST     /vhid/parol/{token}  — зберегти (CSRF), далі — на вхід;
 *   POST     /api/plan/password/link — з Профілю: посилання собі ж у Telegram.
 */
final class PasswordController extends AbstractController
{
    public const string CSRF_REQUEST = 'pw_request';
    public const string CSRF_SET = 'pw_set';

    public function __construct(
        private readonly PasswordSetup $setup,
        #[Autowire('%password_login_enabled%')]
        private readonly bool $enabled,
        #[Autowire('%env(APP_COMPANY_NAME)%')]
        private readonly string $company,
        #[Autowire(service: 'limiter.plan_pw_request_account')]
        private readonly RateLimiterFactoryInterface $accountLimiter,
        #[Autowire(service: 'limiter.plan_pw_request_ip')]
        private readonly RateLimiterFactoryInterface $ipLimiter,
        #[Autowire(service: 'limiter.plan_pw_set_ip')]
        private readonly RateLimiterFactoryInterface $setLimiter,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    #[Route('/vhid/parol', name: 'app_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        if (! $this->enabled) {
            throw $this->createNotFoundException();
        }

        $login = mb_substr(trim((string) $request->request->get('login', $request->getSession()->get(LoginController::LAST_LOGIN, ''))), 0, 180);
        $error = null;
        $sent = false;

        if ($request->isMethod('POST')) {
            if (! $this->isCsrfTokenValid(self::CSRF_REQUEST, (string) $request->request->get('_csrf_token'))) {
                $error = 'Сторінка застаріла. Оновіть її й спробуйте ще раз.';
            } elseif ($login === '') {
                $error = 'Введіть номер телефону.';
            } elseif (! $this->ipLimiter->create('ip:' . $this->key((string) $request->getClientIp()))->consume()->isAccepted()) {
                $error = 'Забагато запитів. Зачекайте кілька хвилин.';
            } else {
                // Ліміт на людину мовчазний: відповідь та сама, просто нового посилання не буде.
                if ($this->accountLimiter->create('acc:' . $this->key(LoginIdentity::normalize($login)))->consume()->isAccepted()) {
                    $this->setup->requestByLogin($login);
                }

                $sent = true;
            }
        }

        return $this->render('security/password_request.html.twig', [
            'company' => $this->company,
            'login' => $login,
            'error' => $error,
            'sent' => $sent,
        ]);
    }

    #[Route('/vhid/parol/{token}', name: 'app_password_set', requirements: ['token' => '[A-Za-z0-9]{1,128}'], methods: ['GET', 'POST'])]
    public function set(Request $request, string $token): Response
    {
        if (! $this->enabled) {
            throw $this->createNotFoundException();
        }

        $row = $this->setup->find($token);
        $error = null;

        if ($row !== null && $request->isMethod('POST')) {
            if (! $this->isCsrfTokenValid(self::CSRF_SET, (string) $request->request->get('_csrf_token'))) {
                $error = 'Сторінка застаріла. Оновіть її й спробуйте ще раз.';
            } elseif (! $this->setLimiter->create('ip:' . $this->key((string) $request->getClientIp()))->consume()->isAccepted()) {
                $error = 'Забагато спроб. Зачекайте кілька хвилин.';
            } else {
                try {
                    $user = $this->setup->complete($token, (string) $request->request->get('password'), (string) $request->request->get('repeat'));
                    // Номер одразу у формі входу — лишається ввести пароль.
                    $request->getSession()->set(LoginController::LAST_LOGIN, PhoneFormat::display($user->getPhoneNumber()) ?? (string) $user->getEmail());

                    return new RedirectResponse($this->generateUrl('app_login', ['parol' => 1]));
                } catch (PasswordSetupException $e) {
                    $error = $e->getMessage();
                }
            }
        }

        $user = $row?->getUser();

        return $this->render('security/password_set.html.twig', [
            'company' => $this->company,
            'valid' => $row !== null,
            'name' => $user?->shortName(),
            'phone' => $user !== null ? PhoneFormat::display($user->getPhoneNumber()) : null,
            'first' => $user !== null && $user->getPassword() === null,
            'error' => $error,
            'min' => PasswordSetup::MIN_LENGTH,
        ], new Response(status: $row === null ? Response::HTTP_GONE : ($error !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK), headers: [
            // Токен у шляху: не в кеш, не в Referer.
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]));
    }

    /** Профіль → «Пароль для входу»: посилання собі ж у Telegram (лише своє, лише з Telegram). */
    #[Route('/api/plan/password/link', name: 'api_plan_password_link', methods: ['POST'])]
    public function link(Request $request): JsonResponse
    {
        $me = $this->getUser();

        if (! $this->enabled || ! $me instanceof TelegramUser) {
            throw $this->createNotFoundException();
        }

        // Лише з власної сторінки Плану (JSON + той самий origin), як інші записи API.
        if (! str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')) {
            return new JsonResponse(['error' => 'Очікується JSON.'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        if (! PasswordSetup::eligible($me)) {
            return new JsonResponse(['sent' => false, 'error' => 'Спершу підключіть Telegram: посилання на пароль приходить лише туди.'], Response::HTTP_CONFLICT);
        }

        if (! $this->accountLimiter->create('acc:' . $this->key('user:' . $me->getId()))->consume()->isAccepted()) {
            return new JsonResponse(['sent' => false, 'error' => 'Посилання вже надіслано кілька разів. Перевірте Telegram або спробуйте за годину.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $sent = $this->setup->sendLink($me, 'Ви попросили посилання в Профілі Плану.');

        return new JsonResponse(['sent' => $sent, 'hasPassword' => $me->getPassword() !== null], $sent ? Response::HTTP_OK : Response::HTTP_BAD_GATEWAY);
    }

    /** Ключі лімітів — HMAC: у кеші немає ні IP, ні номерів відкритим текстом. */
    private function key(string $data): string
    {
        return substr(hash_hmac('sha256', $data, $this->secret), 0, 24);
    }
}
