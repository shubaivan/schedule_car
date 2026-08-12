<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    /** Титульна сторінка стенда: що це і як сюди зайти. */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(#[Autowire('%env(TELEGRAM_BOT_USERNAME)%')] string $botUsername): Response
    {
        return $this->render('home.html.twig', [
            'bot_username' => ltrim($botUsername, '@'),
            'is_manager' => $this->isGranted('ROLE_SUPPLY_MANAGER'),
        ]);
    }
}
