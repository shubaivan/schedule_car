<?php

namespace App\Plan\Share\Controller;

use App\Plan\Share\PlanFiles;
use App\Plan\Share\ShareService;
use App\Tasks\Service\TasksSection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Публічна сторінка тимчасового посилання /s/{token} — для людей поза «Планом»
 * (клієнт, постачальник у Telegram / WhatsApp / Viber).
 *
 * Сторінка, а не одразу файл: прев'ю месенджерів (боти Telegram/WhatsApp) відкривають
 * адресу самі — вони бачать лише назву, файл не скачують і лічильник не крутять.
 * Скачування — окремою кнопкою (/download, завжди attachment). Невідомий токен — 404,
 * відкликаний / прострочений / недоступний — 410. Нічого не індексується й не кешується.
 */
#[Route('/s/{token}', requirements: ['token' => '[A-Za-z0-9_-]{32}'])]
class PublicShareController extends AbstractController
{
    private const array IMAGE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(private readonly ShareService $shares, private readonly PlanFiles $files, private readonly TasksSection $plan)
    {
    }

    /** Посилання /s/… — частина «Плану»; на стендах заявок (TASKS_ENABLED=0) їх немає. */
    private function enabled(): void
    {
        if (! $this->plan->enabled()) {
            throw $this->createNotFoundException();
        }
    }

    #[Route('', name: 'plan_share_page', methods: ['GET'])]
    public function page(string $token): Response
    {
        $this->enabled();
        [$link, $file, $reason] = $this->shares->resolve($token);

        if ($file === null || $link === null) {
            return $this->guard($this->render('plan/share.html.twig', ['reason' => $reason, 'file' => null, 'link' => null, 'token' => null], new Response('', $reason === 'unknown' ? 404 : 410)));
        }

        return $this->guard($this->render('plan/share.html.twig', [
            'reason' => null,
            'file' => $file,
            'link' => $link,
            'token' => $token,
            'image' => in_array($file->mime, self::IMAGE, true),
            'size' => self::size($file->size),
        ]));
    }

    #[Route('/download', name: 'plan_share_download', methods: ['GET'])]
    public function download(string $token, Request $request): Response
    {
        $this->enabled();
        [$link, $file] = $this->shares->resolve($token);

        if ($file === null || $link === null) {
            return $this->guard(new Response('Посилання недійсне.', 410, ['Content-Type' => 'text/plain; charset=UTF-8']));
        }

        $response = PlanFilesController::send($this->files, $file, ResponseHeaderBag::DISPOSITION_ATTACHMENT);

        // Лічимо одне повне скачування: HEAD і докачування шматками (Range) — не нові.
        if ($request->isMethod('GET') && ! $request->headers->has('Range')) {
            $this->shares->recordDownload($link, $file, $request->getClientIp());
        }

        return $this->guard($response);
    }

    /** Прев'ю фото на сторінці посилання (лише растрові картинки, з CSP-пісочницею). */
    #[Route('/view', name: 'plan_share_view', methods: ['GET'])]
    public function view(string $token): Response
    {
        $this->enabled();
        [$link, $file] = $this->shares->resolve($token);

        if ($file === null || $link === null || ! in_array($file->mime, self::IMAGE, true)) {
            return $this->guard(new Response('', 410));
        }

        $response = PlanFilesController::send($this->files, $file, ResponseHeaderBag::DISPOSITION_INLINE);
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $this->guard($response);
    }

    private function guard(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        }

        return $response;
    }

    private static function size(int $bytes): string
    {
        return match (true) {
            $bytes < 1024 => $bytes . ' Б',
            $bytes < 1024 * 1024 => number_format($bytes / 1024, 0, ',', ' ') . ' КБ',
            default => number_format($bytes / 1024 / 1024, 1, ',', ' ') . ' МБ',
        };
    }
}
