<?php

namespace App\Plan\Login;

use App\Controller\LoginController;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Сайтова половина «Увійти через Telegram» (сторінка /vhid, скрипт /plan-login.js).
 *
 *   POST /vhid/telegram/start    — нова спроба для цієї сесії: посилання в бота, код, QR;
 *   GET  /vhid/telegram/status   — стан спроби цієї сесії (скрипт питає кожні 2 с);
 *   POST /vhid/telegram/complete — забрати підтверджений вхід (перехоплює TelegramLoginAuthenticator).
 *
 * Номерів телефонів сайт не приймає взагалі — перебирати нічого. Стан видно лише тій
 * сесії, що почала спробу. Обидва JSON-маршрути мають ліміти на IP і на сесію.
 */
final class TelegramLoginController extends AbstractController
{
    public const string CSRF_ID = 'tg_login';

    public function __construct(
        private readonly TelegramLogin $login,
        private readonly CsrfTokenManagerInterface $csrf,
        #[Autowire(service: 'limiter.plan_tg_login_start')]
        private readonly RateLimiterFactoryInterface $startLimiter,
        #[Autowire(service: 'limiter.plan_tg_login_status')]
        private readonly RateLimiterFactoryInterface $statusLimiter,
    ) {
    }

    #[Route('/vhid/telegram/start', name: 'app_login_telegram_start', methods: ['POST'])]
    public function start(Request $request): JsonResponse
    {
        if (! $this->login->enabled()) {
            return new JsonResponse(['error' => 'Вхід через Telegram не налаштовано.'], Response::HTTP_NOT_FOUND);
        }

        if (! $this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->headers->get('X-CSRF-Token')))) {
            return new JsonResponse(['error' => 'Сторінка застаріла. Оновіть її.'], Response::HTTP_FORBIDDEN);
        }

        if (! $this->startLimiter->create('ip:' . $request->getClientIp())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Забагато спроб. Зачекайте кілька хвилин.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $started = $this->login->start($request->getSession(), $request->getClientIp(), $request->headers->get('User-Agent'));

        return $this->noStore(new JsonResponse([
            'url' => $started['url'],
            'code' => $started['code'],
            'expiresIn' => $started['expiresIn'],
            'qr' => self::qr($started['url']),
        ]));
    }

    #[Route('/vhid/telegram/status', name: 'app_login_telegram_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $session = $request->getSession();

        if (! $this->statusLimiter->create('s:' . hash('sha256', $session->getId() . '|' . $request->getClientIp()))->consume()->isAccepted()) {
            return new JsonResponse(['state' => 'slow'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $this->noStore(new JsonResponse($this->login->status($session)));
    }

    /** Тіло не виконується, коли спроба підтверджена: запит забирає TelegramLoginAuthenticator. */
    #[Route('/vhid/telegram/complete', name: 'app_login_telegram_complete', methods: ['POST'])]
    public function complete(LoginController $page): Response
    {
        return $page->page('Вхід не підтверджено. Натисніть «Увійти через Telegram» ще раз.', status: Response::HTTP_UNAUTHORIZED);
    }

    /** QR того самого посилання — з комп'ютера зручно відсканувати телефоном. */
    private static function qr(string $url): string
    {
        $svg = (new SvgWriter())->write(new QrCode(data: $url, errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 220, margin: 8), options: [
            SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
        ])->getString();

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
