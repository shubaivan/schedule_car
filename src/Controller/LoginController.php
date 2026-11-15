<?php

namespace App\Controller;

use App\Entity\LoginToken;
use App\Entity\TelegramUser;
use App\Plan\Login\TelegramLogin;
use App\Warehouse\Service\WarehouseSection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Сторінка входу — сюди веде будь-яка закрита сторінка без сесії.
 *
 * На «Плані» тут кнопка «Увійти через Telegram» (TelegramLoginController + /plan-login.js),
 * пароль — згорнутий запасний варіант. На стендах заявок вхід — за посиланням із бота
 * (/crm/auth/<токен>), а сторінка пояснює, де його взяти, і чому попереднє не спрацювало.
 */
class LoginController extends AbstractController
{
    /** Останній введений логін — щоб після помилки не набирати знову. */
    public const LAST_LOGIN = '_last_login';

    public function __construct(
        #[Autowire('%env(TELEGRAM_BOT_USERNAME)%')]
        private string $botUsername,
        #[Autowire('%env(APP_COMPANY_NAME)%')]
        private string $company,
        private WarehouseSection $warehouse,
        #[Autowire('%password_login_enabled%')]
        private bool $passwordLogin = false,
        #[Autowire('%tasks_enabled%')]
        private bool $tasks = false,
        private ?TelegramLogin $telegramLogin = null,
        #[Autowire('%env(bool:default::PASSWORD_LOGIN_SUPERADMIN_ONLY)%')]
        private bool $passwordSuperadminOnly = false,
    ) {
    }

    #[Route('/vhid', name: 'app_login', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page($request->query->getBoolean('revoked') ? 'Сесію завершено з Telegram («Це був не я»). Увійдіть знову.' : null, $request->query->getBoolean('out'));
    }

    /**
     * Вхід за паролем. Тіло не виконується, коли PASSWORD_LOGIN_ENABLED=1, —
     * запит перехоплює PasswordLoginAuthenticator. Інакше — звичайна сторінка входу.
     */
    #[Route('/vhid', name: 'app_login_password', methods: ['POST'])]
    public function password(): Response
    {
        return $this->page(null);
    }

    /** Та сама сторінка з причиною відмови — її малює і TelegramLinkAuthenticator. */
    public function page(?string $error, bool $loggedOut = false, int $status = Response::HTTP_OK): Response
    {
        $user = $this->getUser();

        return $this->render('security/login.html.twig', [
            'company' => $this->company,
            'bot_username' => ltrim($this->botUsername, '@'),
            'ttl' => LoginToken::TTL_MINUTES,
            'error' => $error,
            'loggedOut' => $loggedOut && $user === null,
            'warehouse' => $user instanceof TelegramUser && $this->warehouse->inMenuFor($user),
            'password_login' => $this->passwordLogin,
            // «План»: головне — кнопка «Увійти через Telegram»; пароль — згорнутий запасний варіант.
            'telegram_login' => $this->telegramLogin?->enabled() ?? false,
            'password_superadmin_only' => $this->passwordSuperadminOnly,
            'password_tried' => $this->lastLogin() !== '' && $error !== null,
            'tasks' => $this->tasks,
            'last_login' => $this->lastLogin(),
        ], new Response(status: $status));
    }

    private function lastLogin(): string
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();

        return $request !== null && $request->hasSession() ? (string) $request->getSession()->get(self::LAST_LOGIN, '') : '';
    }
}
