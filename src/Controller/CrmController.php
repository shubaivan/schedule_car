<?php

namespace App\Controller;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CrmController extends AbstractController
{
    /**
     * Вхід за одноразовим посиланням із бота.
     * Тіло методу не виконується — запит перехоплює TelegramLinkAuthenticator.
     */
    #[Route('/crm/auth/{token}', name: 'crm_auth', requirements: ['token' => '[a-f0-9]{64}'])]
    public function auth(): Response
    {
        throw new LogicException('Має перехопити TelegramLinkAuthenticator.');
    }

    #[Route('/crm/logout', name: 'crm_logout')]
    public function logout(): Response
    {
        throw new LogicException('Має перехопити файрвол Symfony.');
    }

    /**
     * Оболонка CRM. Будь-який шлях під /crm віддає index.html зібраного Vue-застосунку,
     * щоб глибокі посилання (/crm/requests/42) відкривались напряму.
     */
    #[Route('/crm/{path}', name: 'crm_index', requirements: ['path' => '.*'], defaults: ['path' => ''])]
    public function index(#[Autowire('%kernel.project_dir%')] string $projectDir): Response
    {
        $built = $projectDir . '/public/crm/index.html';

        if (is_readable($built)) {
            return new Response((string) file_get_contents($built));
        }

        // Фронт ще не зібраний (npm run build у frontend/) — показуємо заглушку.
        return $this->render('crm/index.html.twig');
    }
}
