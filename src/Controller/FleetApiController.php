<?php

namespace App\Controller;

use App\Entity\Car;
use App\Entity\DriverPhone;
use App\Entity\TelegramUser;
use App\Service\FleetDirectory;
use App\Supply\Exception\SupplyException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Автопарк у дашборді: машини й водії.
 *
 * Веде керівник — тут роздають машини й закріплюють за ними людей, тож поріг
 * вищий, ніж у заявках: адміністратор, а не будь-який менеджер.
 */
#[IsGranted('ROLE_SUPPLY_ADMIN')]
#[Route('/api/fleet')]
class FleetApiController extends AbstractController
{
    public function __construct(
        private FleetDirectory $fleet,
    ) {
    }

    #[Route('/cars', name: 'api_fleet_cars', methods: ['GET'])]
    public function cars(): JsonResponse
    {
        return $this->json(['items' => array_map($this->car(...), $this->fleet->allCars())]);
    }

    #[Route('/cars', name: 'api_fleet_car_create', methods: ['POST'])]
    public function createCar(Request $request): JsonResponse
    {
        try {
            $car = $this->fleet->saveCar(null, $this->payload($request));
        } catch (SupplyException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->car($car), Response::HTTP_CREATED);
    }

    #[Route('/cars/{id}', name: 'api_fleet_car_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateCar(Car $car, Request $request): JsonResponse
    {
        try {
            $this->fleet->saveCar($car, $this->payload($request));
        } catch (SupplyException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->car($car));
    }

    #[Route('/drivers', name: 'api_fleet_drivers', methods: ['GET'])]
    public function drivers(): JsonResponse
    {
        return $this->json(['items' => array_map($this->driver(...), $this->fleet->allDrivers())]);
    }

    #[Route('/drivers', name: 'api_fleet_driver_create', methods: ['POST'])]
    public function createDriver(Request $request): JsonResponse
    {
        /** @var TelegramUser $user */
        $user = $this->getUser();

        try {
            $entry = $this->fleet->saveDriver(null, $this->payload($request), $user);
        } catch (SupplyException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->driver($entry), Response::HTTP_CREATED);
    }

    #[Route('/drivers/{id}', name: 'api_fleet_driver_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateDriver(DriverPhone $entry, Request $request): JsonResponse
    {
        /** @var TelegramUser $user */
        $user = $this->getUser();

        try {
            $this->fleet->saveDriver($entry, $this->payload($request), $user);
        } catch (SupplyException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->driver($entry));
    }

    #[Route('/drivers/{id}', name: 'api_fleet_driver_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteDriver(DriverPhone $entry): JsonResponse
    {
        $this->fleet->removeDriver($entry);

        return $this->json(['ok' => true]);
    }

    private function car(Car $car): array
    {
        return [
            'id' => $car->getId(),
            'carNumber' => $car->getCarNumber(),
            'model' => $car->getModel(),
            'active' => $car->isActive(),
            'label' => $car->label(),
        ];
    }

    private function driver(DriverPhone $entry): array
    {
        $applied = $entry->getAppliedTo();

        return [
            'id' => $entry->getId(),
            'phone' => $entry->getPhone(),
            'name' => $entry->getName(),
            'note' => $entry->getNote(),
            'carId' => $entry->getCar()?->getId(),
            'carLabel' => $entry->getCar()?->label(),
            'appliedTo' => $applied?->displayName(),
            'appliedAt' => $entry->getAppliedAt()?->format(DATE_ATOM),
        ];
    }

    private function payload(Request $request): array
    {
        $payload = json_decode((string) $request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }
}
