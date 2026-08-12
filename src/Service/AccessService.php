<?php

namespace App\Service;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Enum\SupplyRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Реєстрація в боті та підтвердження доступу.
 *
 * Телефони зі списку `SUPPLY_MANAGER_PHONES` стають менеджерами автоматично —
 * інакше першу людину не було б кому підтвердити.
 */
class AccessService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AccessNotifier $notifier,
        private LoggerInterface $logger,
        #[Autowire('%supply_manager_phones%')] private string $managerPhones,
    ) {
    }

    /** Користувач поділився контактом у боті. */
    public function registerPhone(TelegramUser $user, string $phone): void
    {
        $user->setPhoneNumber(self::normalize($phone));

        if ($this->isBootstrapManager($phone)) {
            $user->setSupplyRole(SupplyRole::Manager)->decideAccess(AccessStatus::Approved, null);
            $this->em->flush();

            $this->logger->info('access: менеджер зі списку зареєструвався', [
                'user' => $user->displayName(),
                'phone' => $user->getPhoneNumber(),
            ]);

            $this->notifier->approved($user);

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

    private function isBootstrapManager(string $phone): bool
    {
        $tail = $this->tail($phone);

        if ($tail === '') {
            return false;
        }

        foreach (explode(',', $this->managerPhones) as $candidate) {
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
