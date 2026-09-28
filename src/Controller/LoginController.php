<?php

namespace App\Controller;

use App\Entity\LoginToken;
use App\Entity\TelegramUser;
use App\Warehouse\Service\WarehouseSection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Сторінка входу — сюди веде будь-яка закрита сторінка без сесії.
 *
 * Сам вхід відбувається не тут, а за посиланням із бота (/crm/auth/<токен>);
 * ця сторінка пояснює, де його взяти, і показує, чому попереднє не спрацювало.
 */
class LoginController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(TELEGRAM_BOT_USERNAME)%')]
        private string $botUsername,
        #[Autowire('%env(APP_COMPANY_NAME)%')]
        private string $company,
        private WarehouseSection $warehouse,
    ) {
    }

    #[Route('/vhid', name: 'app_login', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page(null, $request->query->getBoolean('out'));
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
        ], new Response(status: $status));
    }
}
