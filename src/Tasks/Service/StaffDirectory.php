<?php

namespace App\Tasks\Service;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Event\StaffChanged;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Repository\TelegramUserRepository;
use App\Supply\Entity\Department;
use App\Supply\Repository\DepartmentRepository;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Exception\TaskException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Співробітники «робочого місця»: заведення, ролі, відділи, кошик.
 *
 * Людей не видаляємо назавжди (вимога клієнта 13.11.2026, як «Корзина» в
 * ПланФіксі): через роки має бути видно, хто що писав і які файли додавав.
 *
 * Захист від захоплення облікових записів: картку суперадміна змінює лише
 * суперадмін; пароль, email, телефон і роль ІНШОГО адміністратора — теж лише
 * суперадмін (адмін не може увійти під колегою чи суперадміном, задавши йому
 * пароль). Кожна зміна картки йде в журнал аудиту подією StaffChanged (без значень
 * пароля, email і телефону — лише назви полів). $by = null — консоль, довірена.
 */
class StaffDirectory
{
    public function __construct(
        private EntityManagerInterface $em,
        private TelegramUserRepository $users,
        private DepartmentRepository $departments,
        private UserPasswordHasherInterface $hasher,
        private LoggerInterface $logger,
        private PlanEventRecorder $events,
        private ?TasksSection $plan = null,
    ) {
    }

    /**
     * «План»: пароль людина ставить сама за посиланням із бота (PasswordSetup) — адмін у
     * картці його не задає (за паролем завжди той, чий Telegram підтвердив номер).
     * Консоль ($by = null, plan:user:create) — довірена.
     *
     * @param array<string, mixed> $data
     */
    private function guardPassword(array $data, ?TelegramUser $by, ?TelegramUser $target = null): void
    {
        if ($by !== null && ($data['password'] ?? '') !== '' && ($this->plan?->enabled() ?? false)) {
            // Чужа захищена картка (суперадмін, колега-адмін) — як і раніше 403, а не підказка.
            if ($target !== null && ! self::canChange($by, $target, ['password'])) {
                $this->deny($target, $by, ['password']);
            }

            throw new TaskException('Пароль людина встановлює сама — за посиланням, яке бот надсилає в її Telegram (/parol).');
        }
    }

    /** Поля, що дають вхід у чужий обліковий запис або права: їх у колеги-адміна змінює лише суперадмін. */
    private const array CREDENTIALS = ['email', 'phone', 'role', 'password'];

    /** @return TelegramUser[] */
    public function list(bool $trash): array
    {
        return array_values(array_filter(
            $this->users->findBy([], ['first_name' => 'ASC', 'last_name' => 'ASC']),
            static fn (TelegramUser $u) => $trash ? $u->isArchived() : (! $u->isArchived() && $u->isApproved()),
        ));
    }

    /** @param array<string, mixed> $data $by = null — з консолі (plan:user:create) */
    public function create(array $data, ?TelegramUser $by): TelegramUser
    {
        $user = new TelegramUser()
            // Людина ще не в боті: службовий ідентифікатор, поки не прийде з Telegram.
            ->setTelegramId('staff-' . bin2hex(random_bytes(8)))
            ->setLanguageCode('uk');
        $user->decideAccess(AccessStatus::Approved, $by);

        $this->guardPassword($data, $by);

        // Повний доступ при заведенні — лише від суперадміна (або з консолі).
        if ($by !== null && ! $by->isSuperadmin()) {
            unset($data['fullAccess']);
        }

        $this->apply($user, $data);

        if (! self::canAssignRole($by, $user->getWorkRole())) {
            $this->logger->warning('staff: спроба завести з недозволеною роллю', ['by' => $by?->getId(), 'role' => $user->getWorkRole()->value]);

            throw new AccessDeniedException(self::roleDenial($by));
        }

        if ($user->getFirstName() === null) {
            throw new TaskException('Вкажіть імʼя.');
        }

        // Людина вже є (наприклад, зареєструвалась у боті) — другу картку не заводимо.
        $tail = substr((string) $user->getPhoneNumber(), -9);
        $existing = strlen($tail) === 9 ? $this->users->findOneByPhoneTail($tail) : null;

        if ($existing !== null) {
            throw new TaskException(sprintf(
                'Людина з таким телефоном уже є: %s%s. Відредагуйте її картку%s.',
                $existing->shortName(),
                $existing->isArchived() ? ' (у кошику)' : '',
                $existing->isArchived() ? ' або відновіть з кошика' : '',
            ));
        }

        $this->events->transactional(function () use ($user, $by, $data): void {
            $this->em->persist($user);
            $this->em->flush();
            $this->audit($user, $by, 'create', self::fields($data), roleTo: $user->getWorkRole()->value);
        });
        $this->logger->info('staff: заведено співробітника', ['id' => $user->getId(), 'by' => $by?->getId() ?? 'cli']);

        return $user;
    }

    /** @param array<string, mixed> $data $by = null — з консолі (plan:user:create) */
    public function update(TelegramUser $user, array $data, ?TelegramUser $by): TelegramUser
    {
        $this->guardPassword($data, $by, $user);
        $before = self::snapshot($user);
        $this->apply($user, $data);
        $after = self::snapshot($user);
        // Лише справді змінені поля: форма шле картку цілком, і незмінний email колеги-адміна — не зміна.
        $fields = array_keys(array_diff_assoc($after, $before));

        if (($data['password'] ?? '') !== '') {
            $fields[] = 'password';
        }

        // Роль — та, що була ДО зміни: «зробити колегу-адміна директором» — теж зміна адміна.
        if (! self::canChange($by, $user, $fields, WorkRole::from($before['role']), WorkRole::from($after['role']))) {
            $this->em->refresh($user);
            $this->deny($user, $by, $fields);
        }

        $this->events->transactional(function () use ($user, $by, $fields, $before, $after): void {
            $this->em->flush();

            if ($fields !== []) {
                $role = in_array('role', $fields, true);
                $this->audit($user, $by, 'update', $fields, $role ? $before['role'] : null, $role ? $after['role'] : null);
            }
        });
        $this->logger->info('staff: змінено картку', ['id' => $user->getId(), 'by' => $by?->getId() ?? 'cli']);

        return $user;
    }

    public function trash(TelegramUser $user, TelegramUser $by): void
    {
        if ($user->getId() === $by->getId()) {
            throw new TaskException('Себе в кошик не перенести.');
        }

        $this->guard($user, $by, ['archive']);
        $user->archive($by);
        $this->events->transactional(function () use ($user, $by): void {
            $this->em->flush();
            $this->audit($user, $by, 'trash');
        });
        $this->logger->info('staff: у кошик', ['id' => $user->getId(), 'by' => $by->getId()]);
    }

    public function restore(TelegramUser $user, TelegramUser $by): void
    {
        $this->guard($user, $by, ['archive']);
        $user->restore();
        $this->events->transactional(function () use ($user, $by): void {
            $this->em->flush();
            $this->audit($user, $by, 'restore');
        });
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

        $department = new Department()->setName($name);
        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    /**
     * Хто кому що може змінити (див. докблок класу). Консоль ($by = null) — без обмежень.
     * $targetRole — роль цілі до зміни (за замовчуванням поточна).
     *
     * @param list<string> $fields
     */
    public static function canChange(?TelegramUser $by, TelegramUser $target, array $fields, ?WorkRole $targetRole = null, ?WorkRole $newRole = null): bool
    {
        if ($by === null || $by->isSuperadmin()) {
            return true;
        }

        if ($target->isSuperadmin()) {
            return false;
        }

        // Повний доступ до прихованих розділів (PlanSections) видає й знімає лише суперадмін.
        if (in_array('fullAccess', $fields, true)) {
            return false;
        }

        if (in_array('role', $fields, true)) {
            // Ролі призначають лише адмін, системний адмін і суперадмін (Олександр 17.11);
            // свою — лише суперадмін (інакше системний адмін зробив би себе адміном і побачив задачі).
            if (! $by->managesRoles() || $target->getId() === $by->getId()) {
                return false;
            }

            if ($newRole !== null && ! self::canAssignRole($by, $newRole)) {
                return false;
            }
        }

        // Колега — інший адміністратор чи системний адміністратор: його вхід і права — лише суперадмін.
        $colleague = in_array($targetRole ?? $target->getWorkRole(), [WorkRole::Admin, WorkRole::SysAdmin], true) && $target->getId() !== $by->getId();

        return ! $colleague || array_intersect($fields, [...self::CREDENTIALS, 'archive']) === [];
    }

    /**
     * Чи може людина видати цю роль. Хто сам не бачить задач (системний адміністратор),
     * не видає ролі, що бачать усі задачі заводу (адмін, директор), — інакше обійшов би
     * заборону через підставну картку. Ролі «Адміністратор» і «Системний адміністратор»
     * видає лише той, хто сам керує ролями.
     */
    public static function canAssignRole(?TelegramUser $by, WorkRole $role): bool
    {
        if ($by === null || $by->isSuperadmin()) {
            return true;
        }

        if (in_array($role, [WorkRole::Admin, WorkRole::SysAdmin], true) && ! $by->managesRoles()) {
            return false;
        }

        return $by->canSeeTasks() || ! $role->seesAllTasks();
    }

    private static function roleDenial(?TelegramUser $by): string
    {
        return $by !== null && ! $by->canSeeTasks()
            ? 'Системний адміністратор не видає ролей, які бачать усі задачі (адміністратор, директор).'
            : 'Цю роль може видати лише адміністратор або суперадміністратор.';
    }

    /** @param list<string> $fields */
    private function guard(TelegramUser $target, ?TelegramUser $by, array $fields): void
    {
        if (! self::canChange($by, $target, $fields)) {
            $this->deny($target, $by, $fields);
        }
    }

    /** @param list<string> $fields */
    private function deny(TelegramUser $target, ?TelegramUser $by, array $fields): never
    {
        $this->logger->warning('staff: спроба змінити захищену картку', ['id' => $target->getId(), 'by' => $by?->getId(), 'fields' => $fields]);

        throw new AccessDeniedException(match (true) {
            $target->isSuperadmin() => 'Картку суперадміністратора змінює лише суперадміністратор.',
            in_array('role', $fields, true) && $by !== null && $target->getId() === $by->getId() => 'Свою роль змінює лише суперадміністратор.',
            in_array('role', $fields, true) && $by !== null && ! $by->managesRoles() => 'Ролі призначають лише адміністратор, системний адміністратор і суперадміністратор.',
            in_array('role', $fields, true) && $by !== null && ! $by->canSeeTasks() => 'Системний адміністратор не видає ролей, які бачать усі задачі (адміністратор, директор).',
            default => 'Пароль, контакти, роль і кошик іншого адміністратора — лише суперадміністратор.',
        });
    }

    /** @return array<string, string> поля картки, за якими видно, що саме змінилось */
    private static function snapshot(TelegramUser $user): array
    {
        return [
            'firstName' => (string) $user->getFirstName(),
            'lastName' => (string) $user->getLastName(),
            'position' => (string) $user->getPosition(),
            'email' => (string) $user->getEmail(),
            'phone' => (string) $user->getPhoneNumber(),
            'role' => $user->getWorkRole()->value,
            'departmentId' => (string) $user->getDepartment()?->getId(),
            'fullAccess' => $user->hasPlanFullAccess() ? '1' : '0',
        ];
    }

    /** @param list<string> $fields */
    private function audit(TelegramUser $user, ?TelegramUser $by, string $action, array $fields = [], ?string $roleFrom = null, ?string $roleTo = null): void
    {
        $this->events->record(new StaffChanged(SubjectRef::user((int) $user->getId()), $by?->getId(), action: $action, fields: $fields, roleFrom: $roleFrom, roleTo: $roleTo));
    }

    /**
     * Які поля картки зачіпає запит (пароль — лише непорожній).
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function fields(array $data): array
    {
        $known = ['firstName', 'lastName', 'position', 'email', 'phone', 'role', 'departmentId', 'fullAccess'];
        $fields = array_values(array_filter($known, static fn (string $key) => array_key_exists($key, $data)));

        if (($data['password'] ?? '') !== '') {
            $fields[] = 'password';
        }

        return $fields;
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

            if ($digits !== null && strlen($digits) < 9) {
                throw new TaskException('Телефон — щонайменше 9 цифр.');
            }

            // За телефоном бот упізнає людину при вході через Telegram: дві живі картки
            // з одним номером — і вхід неможливий (PlanLoginBot відмовить), тож не допускаємо.
            $twin = $digits !== null && $user->getId() !== null ? $this->users->findLiveTwinByPhoneTail(substr($digits, -9), (int) $user->getId()) : null;

            if ($twin !== null) {
                throw new TaskException(sprintf('Такий телефон уже має %s.', $twin->shortName()));
            }

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

        if (array_key_exists('fullAccess', $data)) {
            $user->setPlanFullAccess(filter_var($data['fullAccess'], FILTER_VALIDATE_BOOLEAN));
        }

        if (($data['password'] ?? '') !== '') {
            if (mb_strlen((string) $data['password']) < 8) {
                throw new TaskException('Пароль — щонайменше 8 символів.');
            }

            $user->setPassword($this->hasher->hashPassword($user, (string) $data['password']));
            // Пароль від адміна чи консолі — не самостійний: на «Плані» ним не увійти.
            $user->setPasswordSetAt(null);
        }
    }
}
