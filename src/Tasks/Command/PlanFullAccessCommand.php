<?php

namespace App\Tasks\Command;

use App\Entity\TelegramUser;
use App\Plan\Event\StaffChanged;
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
 * Повний доступ до «Плану» (PlanSections): Постачання, Склад, Контрагенти, Автопарк,
 * Диск, «Звʼязок». Без нього людина бачить лише Огляд, Хроніку, Задачі, Пошук,
 * Сповіщення й Людей. Суперадміни мають повний доступ завжди.
 *
 *     php bin/console plan:full-access --list
 *     php bin/console plan:full-access 144 --grant [--dry-run]     # id картки
 *     php bin/console plan:full-access 0671112233 --revoke         # або телефон (≥ 9 цифр, однозначно)
 *
 * Те саме робить суперадмін у картці співробітника. Кожна зміна — у журнал аудиту.
 */
#[AsCommand(name: 'plan:full-access', description: 'Видати або зняти повний доступ до розділів «Плану»')]
class PlanFullAccessCommand extends Command
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
            ->addArgument('who', InputArgument::OPTIONAL, 'id картки або телефон')
            ->addOption('grant', null, InputOption::VALUE_NONE, 'Видати повний доступ')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Зняти повний доступ')
            ->addOption('list', null, InputOption::VALUE_NONE, 'Хто має повний доступ зараз')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показати, що змінилось би, і нічого не записати');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            $rows = [];

            foreach ($this->users->findBy([], ['id' => 'ASC']) as $u) {
                if (! $u->isArchived() && ($u->isSuperadmin() || $u->hasPlanFullAccess())) {
                    $rows[] = [(string) $u->getId(), $u->displayName(), (string) $u->getPhoneNumber(), $u->isSuperadmin() ? 'суперадмін' : 'прапорець'];
                }
            }

            $rows === [] ? $io->note('Ні в кого.') : $io->table(['id', 'Імʼя', 'Телефон', 'Чому'], $rows);

            return Command::SUCCESS;
        }

        $grant = (bool) $input->getOption('grant');

        if ($grant === (bool) $input->getOption('revoke')) {
            $io->error('Вкажіть рівно одне: --grant або --revoke (або --list).');

            return Command::INVALID;
        }

        $user = $this->find(trim((string) $input->getArgument('who')));

        if ($user === null) {
            $io->error('Людину не знайдено: вкажіть id картки або телефон (≥ 9 цифр, має впізнати рівно одну живу картку).');

            return Command::FAILURE;
        }

        $who = sprintf('%s (id %d, %s)', $user->displayName(), (int) $user->getId(), (string) ($user->getPhoneNumber() ?? '—'));

        if ($grant && ($user->isArchived() || ! $user->isApproved())) {
            $io->error($who . ' — у кошику або без підтвердженого доступу.');

            return Command::FAILURE;
        }

        if ($user->hasPlanFullAccess() === $grant) {
            $io->note($who . ': уже ' . ($grant ? 'має' : 'не має') . ' прапорця — нічого не змінено.');

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('[dry-run] %s: повний доступ було б %s.', $who, $grant ? 'видано' : 'знято'));

            return Command::SUCCESS;
        }

        $this->events->transactional(function () use ($user, $grant): void {
            $user->setPlanFullAccess($grant);
            $this->em->flush();
            $this->events->record(new StaffChanged(SubjectRef::user((int) $user->getId()), null, action: 'update', fields: ['fullAccess']));
        });

        $io->success(sprintf('%s: повний доступ %s.', $who, $grant ? 'видано' : 'знято'));

        return Command::SUCCESS;
    }

    private function find(string $who): ?TelegramUser
    {
        if ($who === '') {
            return null;
        }

        if (ctype_digit($who) && strlen($who) < 9) {
            return $this->users->find((int) $who);
        }

        $digits = preg_replace('/\D+/', '', $who) ?? '';

        if (strlen($digits) < 9) {
            return null;
        }

        $matches = array_values(array_filter(
            $this->users->findBy([]),
            static fn (TelegramUser $u) => ! $u->isArchived() && str_ends_with((string) $u->getPhoneNumber(), substr($digits, -9)),
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }
}
