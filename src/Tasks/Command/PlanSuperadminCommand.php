<?php

namespace App\Tasks\Command;

use App\Entity\TelegramUser;
use App\Plan\Event\SubjectUpdated;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Repository\TelegramUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Суперадмін «Плану» (журнал аудиту, бачить усе) — видається ЛИШЕ тут, з
 * консолі сервера; в інтерфейсі роль не редагується й не показується.
 *
 *     php bin/console plan:superadmin ivan@zavod.ua --grant [--dry-run]
 *     php bin/console plan:superadmin 0671112233 --revoke
 *     php bin/console plan:superadmin --list
 *
 * Людину шукаємо за email (як вхід за паролем) або за останніми 9 цифрами
 * телефону. Кожна зміна пишеться в журнал аудиту (user-N, «Змінено», канал cli).
 * Нова роль діє з наступного запиту людини (getRoles() читає прапорець).
 */
#[AsCommand(name: 'plan:superadmin', description: 'Видати або зняти роль суперадміна «Плану» (журнал аудиту)')]
class PlanSuperadminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private TelegramUserRepository $users,
        private PlanEventRecorder $events,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('login', InputArgument::OPTIONAL, 'Email або телефон людини')
            ->addOption('grant', null, InputOption::VALUE_NONE, 'Видати роль суперадміна')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Зняти роль суперадміна')
            ->addOption('list', null, InputOption::VALUE_NONE, 'Показати, у кого роль є зараз')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показати, що змінилось би, і нічого не записати');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            return $this->list($io);
        }

        $grant = (bool) $input->getOption('grant');
        $revoke = (bool) $input->getOption('revoke');
        $dryRun = (bool) $input->getOption('dry-run');
        $login = trim((string) $input->getArgument('login'));

        if ($grant === $revoke) {
            $io->error('Вкажіть рівно одне: --grant або --revoke (або --list).');

            return Command::INVALID;
        }

        if ($login === '') {
            $io->error('Вкажіть email або телефон людини.');

            return Command::INVALID;
        }

        $user = $this->find($login);

        if ($user === null) {
            $io->error(sprintf('Людину «%s» не знайдено (email або щонайменше 9 цифр телефону).', $login));

            return Command::FAILURE;
        }

        $who = sprintf('%s <%s> (id %d)', $user->displayName(), (string) ($user->getEmail() ?? $user->getPhoneNumber() ?? '—'), (int) $user->getId());

        if ($grant && ($user->isArchived() || ! $user->isApproved())) {
            $io->error(sprintf('%s — архівний або без підтвердженого доступу; суперадміном не стане.', $who));

            return Command::FAILURE;
        }

        if ($user->isSuperadmin() === $grant) {
            $io->note(sprintf('%s: уже %s — нічого не змінено.', $who, $grant ? 'суперадмін' : 'не суперадмін'));

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->success(sprintf('[dry-run] %s: роль суперадміна було б %s.', $who, $grant ? 'видано' : 'знято'));

            return Command::SUCCESS;
        }

        $this->events->transactional(function () use ($user, $grant): void {
            $user->setSuperadmin($grant);
            $this->em->flush();
            $this->events->record((new SubjectUpdated(
                SubjectRef::user((int) $user->getId()),
                null,
                fields: ['is_superadmin'],
                summary: $grant ? 'роль суперадміна видано (plan:superadmin)' : 'роль суперадміна знято (plan:superadmin)',
            ))->via('cli'));
        });

        $io->success(sprintf('%s: роль суперадміна %s.', $who, $grant ? 'видано' : 'знято'));

        return Command::SUCCESS;
    }

    private function list(SymfonyStyle $io): int
    {
        $rows = array_map(
            static fn (TelegramUser $u) => [(string) $u->getId(), $u->displayName(), (string) ($u->getEmail() ?? ''), $u->isArchived() ? 'архів' : ''],
            $this->users->findBy(['superadmin' => true], ['id' => 'ASC']),
        );

        if ($rows === []) {
            $io->note('Суперадмінів немає.');

            return Command::SUCCESS;
        }

        $io->table(['id', 'Імʼя', 'Email', ''], $rows);

        return Command::SUCCESS;
    }

    private function find(string $login): ?TelegramUser
    {
        if (str_contains($login, '@')) {
            return $this->users->findOneBy(['email' => mb_strtolower($login)]);
        }

        $digits = preg_replace('/\D+/', '', $login) ?? '';

        return strlen($digits) >= 9 ? $this->users->findOneByPhoneTail(substr($digits, -9)) : null;
    }
}
