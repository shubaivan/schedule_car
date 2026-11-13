<?php

namespace App\Tasks\Service;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Repository\TelegramUserRepository;
use App\Supply\Entity\Department;
use App\Supply\Repository\DepartmentRepository;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Exception\TaskException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Співробітники «робочого місця»: заведення, ролі, відділи, кошик.
 *
 * Людей не видаляємо назавжди (вимога клієнта 13.11.2026, як «Корзина» в
 * ПланФіксі): через роки має бути видно, хто що писав і які файли додавав.
 */
class StaffDirectory
{
    public function __construct(
        private EntityManagerInterface $em,
        private TelegramUserRepository $users,
        private DepartmentRepository $departments,
        private UserPasswordHasherInterface $hasher,
        private LoggerInterface $logger,
    ) {
    }

    /** @return TelegramUser[] */
    public function list(bool $trash): array
    {
        return array_values(array_filter(
            $this->users->findBy([], ['first_name' => 'ASC', 'last_name' => 'ASC']),
            static fn (TelegramUser $u) => $trash ? $u->isArchived() : (! $u->isArchived() && $u->isApproved()),
        ));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, TelegramUser $by): TelegramUser
    {
        $user = (new TelegramUser())
            // Людина ще не в боті: службовий ідентифікатор, поки не прийде з Telegram.
            ->setTelegramId('staff-' . bin2hex(random_bytes(8)))
            ->setLanguageCode('uk');
        $user->decideAccess(AccessStatus::Approved, $by);

        $this->apply($user, $data);

        if ($user->getFirstName() === null) {
            throw new TaskException('Вкажіть імʼя.');
        }

        $this->em->persist($user);
        $this->em->flush();
        $this->logger->info('staff: заведено співробітника', ['id' => $user->getId(), 'by' => $by->getId()]);

        return $user;
    }

    /** @param array<string, mixed> $data */
    public function update(TelegramUser $user, array $data, TelegramUser $by): TelegramUser
    {
        $this->apply($user, $data);
        $this->em->flush();
        $this->logger->info('staff: змінено картку', ['id' => $user->getId(), 'by' => $by->getId()]);

        return $user;
    }

    public function trash(TelegramUser $user, TelegramUser $by): void
    {
        if ($user->getId() === $by->getId()) {
            throw new TaskException('Себе в кошик не перенести.');
        }

        $user->archive($by);
        $this->em->flush();
        $this->logger->info('staff: у кошик', ['id' => $user->getId(), 'by' => $by->getId()]);
    }

    public function restore(TelegramUser $user, TelegramUser $by): void
    {
        $user->restore();
        $this->em->flush();
        $this->logger->info('staff: відновлено з кошика', ['id' => $user->getId(), 'by' => $by->getId()]);
    }

    public function department(string $name): Department
    {
        $name = trim($name);

        if ($name === '') {
            throw new TaskException('Вкажіть назву відділу.');
        }

        if ($this->departments->findOneByName($name) !== null) {
            throw new TaskException(sprintf('Відділ «%s» уже є.', $name));
        }

        $department = (new Department())->setName($name);
        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    /** @param array<string, mixed> $data */
    private function apply(TelegramUser $user, array $data): void
    {
        $text = static fn (string $key): ?string => array_key_exists($key, $data) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        if (array_key_exists('firstName', $data)) {
            $user->setFirstName($text('firstName'));
        }

        if (array_key_exists('lastName', $data)) {
            $user->setLastName($text('lastName'));
        }

        if (array_key_exists('position', $data)) {
            $user->setPosition($text('position'));
        }

        if (array_key_exists('email', $data)) {
            $email = $text('email');

            if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new TaskException('Некоректний email.');
            }

            $existing = $email !== null ? $this->users->findOneBy(['email' => mb_strtolower($email)]) : null;

            if ($existing !== null && $existing->getId() !== $user->getId()) {
                throw new TaskException('Такий email уже має інший співробітник.');
            }

            $user->setEmail($email);
        }

        if (array_key_exists('phone', $data)) {
            $digits = preg_replace('/\D+/', '', (string) $data['phone']) ?: null;
            $user->setPhoneNumber($digits);
        }

        if (array_key_exists('role', $data)) {
            $role = WorkRole::tryFrom((string) $data['role']);

            if ($role === null) {
                throw new TaskException('Невідома роль.');
            }

            $user->setWorkRole($role);
        }

        if (array_key_exists('departmentId', $data)) {
            $id = (int) $data['departmentId'];
            $user->setDepartment($id > 0 ? $this->departments->find($id) : null);
        }

        if (($data['password'] ?? '') !== '') {
            if (mb_strlen((string) $data['password']) < 8) {
                throw new TaskException('Пароль — щонайменше 8 символів.');
            }

            $user->setPassword($this->hasher->hashPassword($user, (string) $data['password']));
        }
    }
}
