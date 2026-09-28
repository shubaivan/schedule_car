<?php

namespace App\Warehouse\Controller;

use App\Repository\CrmLoginRepository;
use DateTime;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Хто й коли заходив у CRM і склад — і хто пробував.
 *
 * Видно всім, хто веде склад: запис, якого ніхто не впізнає, мають помітити
 * того ж дня, а не тоді, коли про нього згадає розробник.
 */
#[Route('/sklad/logins')]
class LoginLogController extends AbstractWarehouseController
{
    private const SHOWN = 300;

    public function __construct(
        private CrmLoginRepository $logins,
    ) {
    }

    #[Route('', name: 'wh_logins', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('warehouse/logins.html.twig', [
            'entries' => $this->logins->latest(self::SHOWN),
            'shown' => self::SHOWN,
            'lastSeen' => $this->logins->lastSeen(),
            'failedWeek' => $this->logins->failedSince(new DateTime('-7 days')),
        ]);
    }
}
