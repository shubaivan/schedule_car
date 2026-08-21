<?php

namespace App\Controller;

use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Fleet\Service\ScheduleBoard;
use DateTime;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Календар завантаження автопарку для дашборда.
 *
 * Поріг навмисно нижчий, ніж у довіднику машин: заводити машини й водіїв —
 * право керівника, а бачити, чим зайнятий парк, потрібно кожному, хто планує
 * поїздку. Той самий розклад бот показує всім у чаті.
 */
#[IsGranted('ROLE_SUPPLY_WORKER')]
#[Route('/api/fleet')]
class FleetScheduleApiController extends AbstractController
{
    /** Скільки днів віддаємо за раз, якщо не попросили інакше. */
    private const DEFAULT_DAYS = 7;
    /** Стеля вікна: рік сіткою ніхто не читає, а вибірка була б важкою. */
    private const MAX_DAYS = 31;

    public function __construct(
        private ScheduleBoard $board,
    ) {
    }

    #[Route('/schedule', name: 'api_fleet_schedule', methods: ['GET'])]
    public function schedule(Request $request): JsonResponse
    {
        $days = min(max((int) $request->query->get('days', (string) self::DEFAULT_DAYS), 1), self::MAX_DAYS);
        $from = $this->from($request);
        $to = (clone $from)->modify(sprintf('+%d days', $days));

        ['cars' => $cars, 'drivers' => $driversByCar, 'sets' => $sets] = $this->board->between($from, $to);

        $tripsByCar = [];

        foreach ($sets as $set) {
            $tripsByCar[(int) $set->getCar()->getId()][] = $this->trip($set);
        }

        $items = [];

        foreach ($cars as $car) {
            $carId = (int) $car->getId();

            $items[] = [
                'id' => $carId,
                'carNumber' => $car->getCarNumber(),
                'label' => $car->label(),
                'drivers' => array_map($this->driver(...), $driversByCar[$carId] ?? []),
                'trips' => $tripsByCar[$carId] ?? [],
            ];
        }

        return $this->json([
            'from' => $from->format('Y-m-d'),
            'days' => array_map(
                static fn (int $i) => (clone $from)->modify(sprintf('+%d days', $i))->format('Y-m-d'),
                range(0, $days - 1),
            ),
            'items' => $items,
        ]);
    }

    /** Початок вікна: або переданий день, або сьогодні за Києвом. */
    private function from(Request $request): DateTime
    {
        $kyiv = new DateTimeZone('Europe/Kyiv');
        $raw = trim((string) $request->query->get('from', ''));

        if ($raw !== '') {
            $parsed = DateTime::createFromFormat('Y-m-d H:i:s', $raw . ' 00:00:00', $kyiv);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        return new DateTime('today', $kyiv);
    }

    private function trip(ScheduledSet $set): array
    {
        $user = $set->getTelegramUserId();

        return [
            'id' => $set->getId(),
            'date' => $set->getScheduledDateTime()->format('Y-m-d'),
            'hour' => $set->getHour(),
            'destination' => $set->getDestination(),
            'task' => $set->getTask(),
            'bookedBy' => $user->displayName(),
            'bookedByPhone' => $user->getPhoneNumber(),
        ];
    }

    private function driver(TelegramUser $driver): array
    {
        return [
            'name' => $driver->displayName(),
            'phone' => $driver->getPhoneNumber(),
        ];
    }
}
