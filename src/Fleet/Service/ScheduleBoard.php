<?php

namespace App\Fleet\Service;

use App\Entity\Car;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Repository\CarDriverRepository;
use App\Repository\CarRepository;
use App\Repository\ScheduledSetRepository;
use DateTime;

/**
 * Джерело даних для календаря завантаження — одне на бот і на CRM.
 *
 * Екрани різні (у чаті розклад читають днями, у дашборді — сіткою «машина ×
 * день»), але картина мусить бути та сама: ті самі машини, ті самі водії, ті
 * самі броні. Тому вибірка живе тут, а не в кожному з двох місць окремо.
 */
class ScheduleBoard
{
    public function __construct(
        private CarRepository $cars,
        private CarDriverRepository $carDrivers,
        private ScheduledSetRepository $sets,
    ) {
    }

    /**
     * @return array{cars: Car[], drivers: array<int, TelegramUser[]>, sets: ScheduledSet[]}
     */
    public function between(DateTime $from, DateTime $to): array
    {
        return [
            'cars' => $this->cars->findBy(['active' => true], ['carNumber' => 'ASC']),
            'drivers' => $this->carDrivers->driversByCar(),
            'sets' => $this->sets->findBetween($from, $to),
        ];
    }
}
