<?php

namespace App\Warehouse\Controller;

use App\Entity\TelegramUser;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Repository\WhActivityRepository;
use App\Warehouse\Service\ActivityLog;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Журнал складу: хто що сканував, куди переходив, що відкривав і міняв.
 *
 * Фільтри — людина, дія, тип картки, період. Той самий журнал, обрізаний до
 * однієї картки, видно внизу кожної картки.
 */
#[Route('/sklad/journal')]
class JournalController extends AbstractWarehouseController
{
    public function __construct(
        private WhActivityRepository $journal,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'wh_journal', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $userId = $request->query->getInt('user');
        $user = $userId ? $this->em->find(TelegramUser::class, $userId) : null;
        $action = ActivityAction::tryFrom((string) $request->query->get('action', ''));
        $type = (string) $request->query->get('type', '');
        $type = isset(ActivityLog::TYPES[$type]) ? $type : null;
        $from = $this->date((string) $request->query->get('from', ''));
        $to = $this->date((string) $request->query->get('to', ''));

        return $this->render('warehouse/journal.html.twig', [
            'entries' => $this->journal->search($user, $action, $type, $from, $to),
            'people' => $this->journal->people(),
            'actions' => ActivityAction::cases(),
            'types' => ActivityLog::TYPES,
            'filters' => [
                'user' => $user?->getId(),
                'action' => $action?->value,
                'type' => $type,
                'from' => $from?->format('Y-m-d'),
                'to' => $to?->format('Y-m-d'),
            ],
        ]);
    }

    private function date(string $value): ?DateTime
    {
        return DateTime::createFromFormat('!Y-m-d', $value) ?: null;
    }
}
