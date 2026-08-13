<?php

namespace App\Supply\Service;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Repository\TelegramUserRepository;
use App\Service\AccessService;
use App\Supply\Entity\StaffPhone;
use App\Supply\Enum\SupplyRole;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\StaffPhoneRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Довідник телефонів: хто ким стане в системі.
 *
 * Запис у довіднику — це обіцянка ролі. Вона виконується двома шляхами:
 * людина реєструється в боті (AccessService питає довідник) або запис заводять
 * на вже зареєстровану людину — тоді роль видається одразу тут.
 */
class StaffDirectory
{
    public function __construct(
        private EntityManagerInterface $em,
        private StaffPhoneRepository $repository,
        private TelegramUserRepository $users,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Завести або оновити запис. Той самий номер не дублюється — оновлюємо.
     */
    public function save(
        string $phone,
        SupplyRole $role,
        ?string $name = null,
        ?string $note = null,
        ?TelegramUser $by = null,
    ): StaffPhone {
        $digits = AccessService::normalize($phone);
        $tail = self::tail($digits);

        if ($tail === '') {
            throw new SupplyException('Вкажіть телефон повністю — щонайменше 9 цифр.');
        }

        $this->assertMayGrant($role, $by);

        $entry = $this->repository->findByTail($tail) ?? new StaffPhone();

        $entry
            ->setPhone($digits)
            ->setTail($tail)
            ->setRole($role)
            ->setName($name !== null && trim($name) !== '' ? trim($name) : $entry->getName())
            ->setNote($note);

        if ($entry->getId() === null) {
            $entry->setCreatedBy($by);
            $this->em->persist($entry);
        }

        $this->em->flush();

        $this->logger->info('supply: запис у довіднику телефонів', [
            'phone' => $digits,
            'role' => $role->value,
            'by' => $by?->displayName(),
        ]);

        $this->applyToRegisteredUser($entry);

        return $entry;
    }

    public function remove(StaffPhone $entry, ?TelegramUser $by = null): void
    {
        $this->assertMayGrant($entry->getRole(), $by);

        // Роль, яку вже видали людині, не забираємо: це окреме рішення,
        // його ухвалюють у розділі «Люди».
        $this->em->remove($entry);
        $this->em->flush();
    }

    /** Що обіцяно цьому номеру. Питає AccessService під час реєстрації. */
    public function lookup(string $phone): ?StaffPhone
    {
        return $this->repository->findByTail(self::tail(AccessService::normalize($phone)));
    }

    /** @return StaffPhone[] */
    public function all(): array
    {
        return $this->repository->findAllOrdered();
    }

    /**
     * Людина вже в боті — видаємо роль негайно, не чекаючи повторної реєстрації.
     * Мовчки нічого не змінюємо, якщо роль уже така сама.
     */
    private function applyToRegisteredUser(StaffPhone $entry): void
    {
        $user = $this->users->findOneByPhoneTail($entry->getTail());

        if ($user === null) {
            return;
        }

        $changed = $user->getSupplyRole() !== $entry->getRole() || ! $user->isApproved();

        $user->setSupplyRole($entry->getRole());

        if (! $user->isApproved()) {
            $user->decideAccess(AccessStatus::Approved, null);
        }

        $entry->markApplied($user);
        $this->em->flush();

        if ($changed) {
            $this->logger->info('supply: роль із довідника видано наявному користувачу', [
                'user' => $user->displayName(),
                'role' => $entry->getRole()->value,
            ]);
        }
    }

    /** Адміністратора призначає лише адміністратор — інакше роль можна собі підняти. */
    private function assertMayGrant(SupplyRole $role, ?TelegramUser $by): void
    {
        if ($by === null) {
            return;
        }

        if (! $by->getSupplyRole()->canManage()) {
            throw new SupplyException('Вести довідник телефонів може менеджер із постачання.');
        }

        if ($role === SupplyRole::Admin && $by->getSupplyRole() !== SupplyRole::Admin) {
            throw new SupplyException('Роль адміністратора призначає лише адміністратор.');
        }
    }

    public static function tail(string $digits): string
    {
        return strlen($digits) >= StaffPhone::TAIL_LENGTH
            ? substr($digits, -StaffPhone::TAIL_LENGTH)
            : '';
    }
}
