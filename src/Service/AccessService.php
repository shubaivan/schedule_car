<?php

namespace App\Service;

use App\Entity\DriverPhone;
use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Enum\SupplyRole;
use App\Supply\Service\StaffDirectory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Реєстрація в боті та підтвердження доступу.
 *
 * Роль при реєстрації дає довідник телефонів: занесли номер Наталії Григорівни
 * як директора — вона ним і стане, щойно поділиться контактом. Списки
 * `SUPPLY_MANAGER_PHONES` і `SUPPLY_DIRECTOR_PHONES` лишаються запасним
 * варіантом для голого стенда, де в довіднику ще нічого немає.
 */
class AccessService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AccessNotifier $notifier,
        private LoggerInterface $logger,
        private StaffDirectory $staff,
        private FleetDirectory $fleet,
        #[Autowire('%supply_manager_phones%')]
        private string $managerPhones,
        #[Autowire('%supply_director_phones%')]
        private string $directorPhones = '',
    ) {
    }

    /** Користувач поділився контактом у боті. */
    public function registerPhone(TelegramUser $user, string $phone): void
    {
        $user->setPhoneNumber(self::normalize($phone));

        // Довідник водіїв питаємо першим: керівник автопарку вніс номер, отже
        // людина своя — доступ відкривається без окремого підтвердження.
        $driver = $this->fleet->assignOnRegistration($user, $phone);

        $listed = $this->staff->lookup($phone);
        $bootstrapRole = $listed?->getRole() ?? $this->bootstrapRole($phone);

        if ($bootstrapRole !== null) {
            $user->setSupplyRole($bootstrapRole)->decideAccess(AccessStatus::Approved, null);
            $listed?->markApplied($user);
            $this->em->flush();

            $this->logger->info('access: людина з довідника зареєструвалась', [
                'user' => $user->displayName(),
                'phone' => $user->getPhoneNumber(),
                'role' => $bootstrapRole->value,
                'source' => $listed !== null ? 'staff' : 'env',
            ]);

            $this->notifier->approved($user);
            $this->announceDriver($user, $driver);

            return;
        }

        if ($driver !== null) {
            $this->em->flush();

            $this->logger->info('fleet: водій із довідника зареєструвався', [
                'user' => $user->displayName(),
                'car' => $driver->getCar()?->getCarNumber(),
            ]);

            $this->notifier->approved($user);
            $this->announceDriver($user, $driver);

            return;
        }

        if ($user->isApproved()) {
            $this->em->flush();

            return;
        }

        // Повторна спроба після відмови знову йде на розгляд.
        $user->decideAccess(AccessStatus::Pending, null);
        $this->em->flush();

        $this->notifier->registrationRequested($user);
    }

    private function announceDriver(TelegramUser $user, ?DriverPhone $driver): void
    {
        if ($driver !== null) {
            $this->notifier->driverAssigned($user, $driver->getCar());
        }
    }

    public function decide(TelegramUser $user, AccessStatus $status, TelegramUser $by): void
    {
        $user->decideAccess($status, $by);
        $this->em->flush();

        $this->logger->info('access: рішення по реєстрації', [
            'user' => $user->displayName(),
            'phone' => $user->getPhoneNumber(),
            'status' => $status->value,
            'by' => $by->displayName(),
        ]);

        $status === AccessStatus::Approved
            ? $this->notifier->approved($user)
            : $this->notifier->rejected($user);
    }

    /** Лише цифри: Telegram віддає контакт то з «+», то без нього. */
    public static function normalize(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /** Директор має пріоритет: якщо номер трапився в обох списках, це директор. */
    private function bootstrapRole(string $phone): ?SupplyRole
    {
        $tail = $this->tail($phone);

        if ($tail === '') {
            return null;
        }

        if ($this->listed($this->directorPhones, $tail)) {
            return SupplyRole::Director;
        }

        return $this->listed($this->managerPhones, $tail) ? SupplyRole::Manager : null;
    }

    private function listed(string $phones, string $tail): bool
    {
        foreach (explode(',', $phones) as $candidate) {
            if ($this->tail($candidate) === $tail) {
                return true;
            }
        }

        return false;
    }

    /**
     * Останні 9 цифр: той самий номер приходить як 380678338298, +380678338298
     * або 0678338298 — порівнювати можна лише хвіст.
     */
    private function tail(string $phone): string
    {
        $digits = self::normalize($phone);

        return strlen($digits) >= 9 ? substr($digits, -9) : '';
    }
}
