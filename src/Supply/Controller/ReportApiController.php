<?php

namespace App\Supply\Controller;

use App\Supply\Service\SupplyReports;
use DateTime;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Звіти по постачанню. Доступ — разом з усім /api, лише менеджерам. */
#[Route('/api/supply/reports')]
class ReportApiController extends AbstractController
{
    #[Route('', name: 'api_supply_reports', methods: ['GET'])]
    public function build(Request $request, SupplyReports $reports): JsonResponse
    {
        $today = new DateTime('today', new DateTimeZone('Europe/Kyiv'));

        // За замовчуванням — поточний місяць: саме його питають найчастіше.
        $from = $this->date((string) $request->query->get('from'), (clone $today)->modify('first day of this month'));
        $to = $this->date((string) $request->query->get('to'), $today);

        if ($from === null || $to === null) {
            return $this->json(['error' => 'Дати мають бути у форматі РРРР-ММ-ДД.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($from > $to) {
            return $this->json(['error' => 'Початок періоду пізніше за кінець.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($reports->build($from, $to));
    }

    private function date(string $value, DateTime $default): ?DateTime
    {
        if ($value === '') {
            return $default;
        }

        return DateTime::createFromFormat('Y-m-d H:i:s', $value . ' 00:00:00', new DateTimeZone('Europe/Kyiv')) ?: null;
    }
}
