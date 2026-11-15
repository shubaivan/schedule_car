<?php

namespace App\Tasks\Command;

use App\Repository\TelegramUserRepository;
use App\Supply\Repository\DepartmentRepository;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Exception\TaskException;
use App\Tasks\Service\StaffDirectory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Завести (або оновити) живого співробітника «Плану» з паролем — для бойового
 * стенду, де tasks:demo-seed не працює. Людину шукаємо за email: є — оновлюємо картку,
 * немає — заводимо так само, як CRM («Співробітники» → «Додати»).
 *
 *     php bin/console plan:user:create --email=ivan@zavod.ua --name="Іван Петренко" \
 *         --role=director --department="1. Дирекція" [--phone=0671112233] [--position=Директор]
 *
 * Пароль — лише з клавіатури (прихований ввід, двічі) або з stdin (--password-stdin):
 *
 *     printf '%s\n' "$PASS" | php bin/console plan:user:create --email=… --password-stdin
 *
 * Пароля в аргументах немає навмисно: він потрапив би в історію shell і в `ps`.
 * --dry-run перевіряє все (email, телефон, роль, відділ) у транзакції й відкочує її.
 */
#[AsCommand(name: 'plan:user:create', description: 'Завести або оновити співробітника «Плану» з паролем')]
class PlanUserCreateCommand extends Command
{
    public const MIN_PASSWORD = 10;

    public function __construct(
        private EntityManagerInterface $em,
        private StaffDirectory $staff,
        private TelegramUserRepository $users,
        private DepartmentRepository $departments,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Логін (email); за ним шукаємо, чи людина вже є')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Імʼя та прізвище, напр. "Іван Петренко"')
            ->addOption('phone', null, InputOption::VALUE_REQUIRED, 'Телефон (необовʼязково) — за ним людину впізнає бот')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'admin | director | manager | accountant | production')
            ->addOption('department', null, InputOption::VALUE_REQUIRED, 'Назва відділу, напр. "3. Відділ продажу" (немає — буде створено)')
            ->addOption('position', null, InputOption::VALUE_REQUIRED, 'Посада, напр. "Менеджер з продажу"')
            ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Прочитати пароль із першого рядка stdin')
            ->addOption('keep-password', null, InputOption::VALUE_NONE, 'Оновлення без зміни пароля')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Перевірити й нічого не записати');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $email = mb_strtolower(trim((string) $input->getOption('email')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('--email: вкажіть коректний email — це логін людини.');

            return Command::INVALID;
        }

        $existing = $this->users->findOneBy(['email' => $email]);

        // Демо-людину tasks:demo-seed --reset видалить разом з усім — живу картку на ній не будуємо.
        if ($existing !== null && str_starts_with((string) $existing->getTelegramId(), 'demo-')) {
            $io->error('Цей email належить демо-користувачу (його прибирає tasks:demo-seed --reset). Візьміть інший email.');

            return Command::FAILURE;
        }

        $data = ['email' => $email];

        $name = trim((string) $input->getOption('name'));

        if ($name !== '') {
            $parts = preg_split('/\s+/u', $name, 2) ?: [$name];
            $data['firstName'] = $parts[0];
            $data['lastName'] = $parts[1] ?? '';
        } elseif ($existing === null) {
            $io->error('--name: для нового співробітника потрібні імʼя та прізвище.');

            return Command::INVALID;
        }

        $role = $input->getOption('role');

        if ($role !== null) {
            if (WorkRole::tryFrom((string) $role) === null) {
                $io->error('--role: ' . implode(' | ', array_map(static fn (WorkRole $r) => $r->value, WorkRole::cases())) . '.');

                return Command::INVALID;
            }

            $data['role'] = (string) $role;
        } elseif ($existing === null) {
            $io->error('--role: для нового співробітника вкажіть роль.');

            return Command::INVALID;
        }

        if ($input->getOption('phone') !== null) {
            $data['phone'] = (string) $input->getOption('phone');

            if (strlen((string) preg_replace('/\D+/', '', $data['phone'])) < 9) {
                $io->error('--phone: щонайменше 9 цифр.');

                return Command::INVALID;
            }
        }

        if ($input->getOption('position') !== null) {
            $data['position'] = (string) $input->getOption('position');
        }

        $keep = (bool) $input->getOption('keep-password');

        if ($keep && $existing === null) {
            $io->error('--keep-password лише для оновлення: у нового співробітника пароля ще немає.');

            return Command::INVALID;
        }

        // Пароль питаємо до транзакції; у dry-run не питаємо зовсім.
        if (! $dryRun && ! $keep) {
            $password = $this->readPassword($input, $io);

            if ($password === null) {
                return Command::INVALID;
            }

            $data['password'] = $password;
        }

        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            $departmentName = trim((string) $input->getOption('department'));
            $createdDepartment = false;

            if ($departmentName !== '') {
                $department = $this->departments->findOneByName($departmentName);

                if ($department === null) {
                    $department = $this->staff->department($departmentName);
                    $createdDepartment = true;
                } elseif (! $department->isActive()) {
                    $department->setActive(true);
                }

                $data['departmentId'] = $department->getId();
            }

            $user = $existing === null
                ? $this->staff->create($data, null)
                : $this->staff->update($existing, $data, null);

            if (! $user->isApproved()) {
                $io->warning('Доступ людини не підтверджено — увійти не зможе, поки її не підтвердять.');
            }

            if ($user->isArchived()) {
                $io->warning('Людина в кошику — увійти не зможе, поки її не відновлять у «Співробітниках».');
            }

            $dryRun ? $conn->rollBack() : $conn->commit();
        } catch (TaskException $e) {
            $conn->rollBack();
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (Throwable $e) {
            $conn->rollBack();

            throw $e;
        }

        unset($data['password']);

        $io->definitionList(
            ['Хто' => $user->displayName()],
            ['Логін' => (string) $user->getEmail()],
            ['Роль' => $user->getWorkRole()->label()],
            ['Відділ' => ($user->getDepartment()?->getName() ?? '—') . ($createdDepartment ? ' (новий)' : '')],
            ['Телефон' => $user->getPhoneNumber() ?? '—'],
            ['Пароль' => $keep || $dryRun ? 'без змін' : 'встановлено'],
        );

        $verb = $existing === null ? 'заведено' : 'оновлено';
        $dryRun
            ? $io->note('Dry run: усе перевірено, нічого не записано (співробітника було б ' . $verb . ').')
            : $io->success('Співробітника ' . $verb . '.');

        return Command::SUCCESS;
    }

    private function readPassword(InputInterface $input, SymfonyStyle $io): ?string
    {
        if ($input->getOption('password-stdin')) {
            $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? STDIN;
            $password = rtrim((string) fgets($stream), "\r\n");
        } else {
            if (! $input->isInteractive()) {
                $io->error('Пароль: запустіть інтерактивно або передайте його через --password-stdin.');

                return null;
            }

            $password = (string) $io->askQuestion(new Question('Пароль (не відображається)')->setHidden(true)->setHiddenFallback(false));
            $again = (string) $io->askQuestion(new Question('Ще раз')->setHidden(true)->setHiddenFallback(false));

            if ($password !== $again) {
                $io->error('Паролі не збігаються.');

                return null;
            }
        }

        if (mb_strlen($password) < self::MIN_PASSWORD) {
            $io->error(sprintf('Пароль — щонайменше %d символів.', self::MIN_PASSWORD));

            return null;
        }

        return $password;
    }
}
