<?php

namespace App\Service;

use App\Entity\Car;
use App\Entity\CarDriver;
use App\Entity\DriverPhone;
use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Repository\CarDriverRepository;
use App\Repository\CarRepository;
use App\Repository\DriverPhoneRepository;
use App\Repository\TelegramUserRepository;
use App\Supply\Exception\SupplyException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Автопарк: машини й водії, які веде керівник у дашборді.
 *
 * Водія заводять номером телефону, а не «створюють користувача»: акаунт у
 * Telegram людина створює собі сама. Запис у довіднику — це обіцянка, яка
 * виконується, щойно водій натисне «Старт» і поділиться номером. Якщо він уже
 * в боті — прив'язка спрацьовує одразу, чекати другої реєстрації не треба.
 */
class FleetDirectory
{
    public function __construct(
        private EntityManagerInterface $em,
        private CarRepository $cars,
        private DriverPhoneRepository $drivers,
        private CarDriverRepository $carDrivers,
        private TelegramUserRepository $users,
        private LoggerInterface $logger,
    ) {
    }

    /** @return Car[] */
    public function allCars(): array
    {
        return $this->cars->findBy([], ['carNumber' => 'ASC']);
    }

    public function saveCar(?Car $car, array $payload): Car
    {
        $number = trim((string) ($payload['carNumber'] ?? $car?->getCarNumber() ?? ''));

        if ($number === '') {
            throw new SupplyException('Вкажіть державний номер машини.');
        }

        $duplicate = $this->cars->findOneBy(['carNumber' => $number]);

        if ($duplicate !== null && $duplicate !== $car) {
            throw new SupplyException(sprintf('Машина з номером %s уже є в списку.', $number));
        }

        $car ??= new Car();
        $car->setCarNumber($number);

        if (array_key_exists('model', $payload)) {
            $model = trim((string) $payload['model']);
            $car->setModel($model !== '' ? $model : null);
        }

        if (array_key_exists('active', $payload)) {
            $car->setActive((bool) $payload['active']);
        }

        if ($car->getId() === null) {
            $this->em->persist($car);
        }

        $this->em->flush();

        return $car;
    }

    /** @return DriverPhone[] */
    public function allDrivers(): array
    {
        return $this->drivers->findAllOrdered();
    }

    /** Завести або оновити водія. Той самий номер не дублюємо — оновлюємо. */
    public function saveDriver(?DriverPhone $entry, array $payload, ?TelegramUser $by = null): DriverPhone
    {
        $rawPhone = (string) ($payload['phone'] ?? $entry?->getPhone() ?? '');
        $digits = AccessService::normalize($rawPhone);
        $tail = self::tail($digits);

        if ($tail === '') {
            throw new SupplyException('Вкажіть телефон повністю — щонайменше 9 цифр.');
        }

        $existing = $this->drivers->findByTail($tail);

        if ($existing !== null && $entry !== null && $existing !== $entry) {
            throw new SupplyException('Цей номер уже записаний за іншим водієм.');
        }

        $entry ??= $existing ?? new DriverPhone();

        $entry->setPhone($digits)->setTail($tail);

        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            $entry->setName($name !== '' ? $name : null);
        }

        if (array_key_exists('note', $payload)) {
            $note = trim((string) $payload['note']);
            $entry->setNote($note !== '' ? $note : null);
        }

        if (array_key_exists('carId', $payload)) {
            $carId = $payload['carId'];
            $entry->setCar($carId !== null && $carId !== '' ? $this->cars->find((int) $carId) : null);
        }

        if ($entry->getId() === null) {
            $entry->setCreatedBy($by);
            $this->em->persist($entry);
        }

        $this->em->flush();

        $this->logger->info('fleet: запис у довіднику водіїв', [
            'phone' => $digits,
            'car' => $entry->getCar()?->getCarNumber(),
            'by' => $by?->displayName(),
        ]);

        $this->applyToRegisteredUser($entry);

        return $entry;
    }

    public function removeDriver(DriverPhone $entry): void
    {
        // Саму прив'язку «машина ↔ водій» теж знімаємо: запис у довіднику й був
        // її причиною, інакше людина лишиться водієм без жодного сліду про це.
        $user = $entry->getAppliedTo();

        if ($user !== null) {
            foreach ($this->carDrivers->findBy(['driver' => $user]) as $link) {
                $this->em->remove($link);
            }
        }

        $this->em->remove($entry);
        $this->em->flush();
    }

    /**
     * Людина зареєструвалась у боті. Якщо вона в довіднику водіїв — прив'язуємо
     * до машини й відкриваємо доступ: її вніс керівник, отже вона своя.
     */
    public function assignOnRegistration(TelegramUser $user, string $phone): ?DriverPhone
    {
        $entry = $this->drivers->findByTail(self::tail(AccessService::normalize($phone)));

        if ($entry === null) {
            return null;
        }

        $this->link($entry, $user);

        return $entry;
    }

    /** Запис завели на людину, яка вже в боті — прив'язуємо негайно. */
    private function applyToRegisteredUser(DriverPhone $entry): void
    {
        $user = $this->users->findOneByPhoneTail($entry->getTail());

        if ($user !== null) {
            $this->link($entry, $user);
        }
    }

    private function link(DriverPhone $entry, TelegramUser $user): void
    {
        if (! $user->isApproved()) {
            $user->decideAccess(AccessStatus::Approved, null);
        }

        $car = $entry->getCar();

        if ($car !== null) {
            $link = $this->carDrivers->findOneBy(['driver' => $user, 'car' => $car]);

            if ($link === null) {
                $link = new CarDriver();
                $link->setCar($car);
                $link->setDriver($user);
                $this->em->persist($link);
            }
        }

        $entry->markApplied($user);
        $this->em->flush();

        $this->logger->info('fleet: водія прив\'язано', [
            'user' => $user->displayName(),
            'car' => $car?->getCarNumber(),
        ]);
    }

    public static function tail(string $digits): string
    {
        return strlen($digits) >= DriverPhone::TAIL_LENGTH
            ? substr($digits, -DriverPhone::TAIL_LENGTH)
            : '';
    }
}
