<?php

namespace App\Supply\Service;

use App\Entity\CarDriver;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Repository\TelegramUserRepository;
use App\Service\AccessService;
use App\Supply\Entity\SupplyComment;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Entity\SupplyStatusLog;
use App\Supply\Enum\SupplyRole;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\DepartmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Люди в системі: погодити доступ, поправити картку, прибрати зі списку.
 *
 * Веде їх менеджер із постачання — він і так підтверджує реєстрації в боті.
 * Телефон не редагується навмисно: саме за ним людина чіпляється до свого
 * запису під час реєстрації, і підміна номера рве цей зв'язок.
 */
class PeopleDirectory
{
    public function __construct(
        private EntityManagerInterface $em,
        private TelegramUserRepository $users,
        private DepartmentRepository $departments,
        private AccessService $access,
        private LoggerInterface $logger,
    ) {
    }

    /** @return TelegramUser[] */
    public function all(bool $withArchived = false): array
    {
        return $this->users->findPeople($withArchived);
    }

    /**
     * @param array{firstName?: ?string, lastName?: ?string, role?: string, departmentId?: ?int} $fields
     */
    public function update(TelegramUser $user, array $fields, TelegramUser $by): TelegramUser
    {
        $this->assertManages($by, $user);

        if (array_key_exists('firstName', $fields) || array_key_exists('lastName', $fields)) {
            $first = $this->text($fields['firstName'] ?? $user->getFirstName());
            $last = $this->text($fields['lastName'] ?? $user->getLastName());

            if ($first === null && $last === null) {
                throw new SupplyException('Лишіть хоча б ім\'я — інакше в заявках нікого не впізнати.');
            }

            $user->setFirstName($first)->setLastName($last);
        }

        if (array_key_exists('role', $fields)) {
            $role = SupplyRole::tryFrom((string) $fields['role']);

            if ($role === null) {
                throw new SupplyException('Невідома роль.');
            }

            $this->assertMayGrant($role, $by, $user);
            $user->setSupplyRole($role);
        }

        if (array_key_exists('departmentId', $fields)) {
            $department = $fields['departmentId'] !== null
                ? $this->departments->find((int) $fields['departmentId'])
                : null;

            if ($fields['departmentId'] !== null && $department === null) {
                throw new SupplyException('Підрозділ не знайдено.');
            }

            $user->setDepartment($department);
        }

        $this->em->flush();

        $this->logger->info('supply: картку людини змінено', [
            'user' => $user->displayName(),
            'by' => $by->displayName(),
        ]);

        return $user;
    }

    /** Погодити чи відхилити доступ — те саме рішення, що кнопками в боті. */
    public function decide(TelegramUser $user, AccessStatus $status, TelegramUser $by): TelegramUser
    {
        $this->assertManages($by, $user);

        if ($user->getId() === $by->getId()) {
            throw new SupplyException('Свій власний доступ вирішувати не можна.');
        }

        $this->access->decide($user, $status, $by);

        // Погодження повертає людину до списку: раз доступ відкрито, тримати
        // її в архіві безглуздо.
        if ($status === AccessStatus::Approved && $user->isArchived()) {
            $user->restore();
            $this->em->flush();
        }

        return $user;
    }

    /**
     * Прибрати людину зі списку.
     *
     * @return bool true — видалено назовсім, false — прибрано в архів разом із закритим доступом
     */
    public function remove(TelegramUser $user, TelegramUser $by): bool
    {
        $this->assertManages($by, $user);

        if ($user->getId() === $by->getId()) {
            throw new SupplyException('Себе зі списку прибрати не можна.');
        }

        if ($this->hasHistory($user)) {
            $user->archive();
            $this->em->flush();

            // Доступ закриваємо окремим рішенням: прибрана людина не має
            // подавати заявки, поки її не повернуть.
            if (! $user->isRejected()) {
                $this->access->decide($user, AccessStatus::Rejected, $by);
            }

            $this->logger->info('supply: людину прибрано в архів', [
                'user' => $user->displayName(),
                'by' => $by->displayName(),
            ]);

            return false;
        }

        $name = $user->displayName();

        $this->em->remove($user);
        $this->em->flush();

        $this->logger->info('supply: людину видалено', ['user' => $name, 'by' => $by->displayName()]);

        return true;
    }

    public function restore(TelegramUser $user, TelegramUser $by): TelegramUser
    {
        $this->assertManages($by, $user);

        $user->restore();
        $this->em->flush();

        return $user;
    }

    /** Чи тримається за людину хоч щось, що не можна втратити разом із нею. */
    private function hasHistory(TelegramUser $user): bool
    {
        $entities = [
            SupplyRequest::class => 'author',
            SupplyComment::class => 'author',
            SupplyStatusLog::class => 'author',
            ScheduledSet::class => 'telegramUserId',
            CarDriver::class => 'driver',
        ];

        foreach ($entities as $class => $field) {
            $count = (int) $this->em->createQuery(
                sprintf('SELECT COUNT(e.id) FROM %s e WHERE e.%s = :user', $class, $field),
            )->setParameter('user', $user)->getSingleScalarResult();

            if ($count > 0) {
                return true;
            }
        }

        return false;
    }

    private function assertManages(TelegramUser $by, TelegramUser $target): void
    {
        if (! $by->getSupplyRole()->canManage()) {
            throw new SupplyException('Вести список людей може менеджер із постачання.');
        }

        // Адміністратора чіпає лише адміністратор — інакше менеджер міг би
        // зняти того, хто його призначив.
        if ($target->getSupplyRole() === SupplyRole::Admin && $by->getSupplyRole() !== SupplyRole::Admin) {
            throw new SupplyException('Картку адміністратора змінює лише адміністратор.');
        }
    }

    private function assertMayGrant(SupplyRole $role, TelegramUser $by, TelegramUser $target): void
    {
        if ($role === SupplyRole::Admin && $by->getSupplyRole() !== SupplyRole::Admin) {
            throw new SupplyException('Роль адміністратора призначає лише адміністратор.');
        }

        if ($target->getId() === $by->getId() && $role !== $by->getSupplyRole()) {
            throw new SupplyException('Свою власну роль змінити не можна.');
        }
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
