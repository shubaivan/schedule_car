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

    /**
     * Інструкція для працівників: як подати заявку, обробити її та відстежити.
     *
     * Живе поруч із самою системою, на її ж адресі, — щоб посилання можна було
     * кинути в робочий чат і воно не залежало ні від чого стороннього.
     */
    #[Route('/instrukciya', name: 'app_instrukciya', methods: ['GET'])]
    public function instrukciya(): Response
    {
        return $this->render('instrukciya.html.twig');
    }
}
