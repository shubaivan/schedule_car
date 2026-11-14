<?php

namespace App\Controller;

use App\Tasks\Service\TasksSection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    /**
     * Титульна сторінка стенда: що це і як сюди зайти.
     * Стенд «Плану» (CRM_HOME=tasks) титулки заявок не має — одразу в План (там і вхід).
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(#[Autowire('%env(TELEGRAM_BOT_USERNAME)%')] string $botUsername, TasksSection $tasks): Response
    {
        if ($tasks->home() === 'tasks') {
            return $this->redirectToRoute('crm_index', ['path' => 'tasks']);
        }

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
    /** Разове підключення Google Диска — інструкція для керівництва клієнта. */
    #[Route('/instrukciya/google-drive', name: 'app_instrukciya_drive', methods: ['GET'])]
    public function instrukciyaDrive(#[Autowire('%env(APP_COMPANY_NAME)%')] string $company): Response
    {
        return $this->render('instrukciya-drive.html.twig', ['company' => $company]);
    }

    /**
     * Політика конфіденційності — публічна: її посилання вимагає Google, щоб
     * опублікувати застосунок для підключення Диска.
     */
    #[Route('/privacy-policy', name: 'app_privacy_policy', methods: ['GET'])]
    public function privacyPolicy(
        #[Autowire('%env(APP_COMPANY_NAME)%')]
        string $company,
        #[Autowire('%env(TELEGRAM_BOT_USERNAME)%')]
        string $botUsername,
    ): Response {
        return $this->render('privacy-policy.html.twig', [
            'company' => $company,
            'bot_username' => ltrim($botUsername, '@'),
        ]);
    }

    #[Route('/instrukciya', name: 'app_instrukciya', methods: ['GET'])]
    public function instrukciya(
        #[Autowire('%env(TELEGRAM_BOT_USERNAME)%')]
        string $botUsername,
        #[Autowire('%env(APP_COMPANY_NAME)%')]
        string $company,
    ): Response {
        return $this->render('instrukciya.html.twig', [
            'bot_username' => ltrim($botUsername, '@'),
            'company' => $company,
        ]);
    }
}
