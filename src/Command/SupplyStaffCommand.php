<?php

namespace App\Command;

use App\Supply\Enum\SupplyRole;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\StaffPhoneRepository;
use App\Supply\Service\StaffDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Довідник телефонів із консолі — щоб завести директора на стенді до того,
 * як хтось зайде в CRM:
 *
 *     php bin/console supply:staff 0671112233 --role=director --name="Наталія Григорівна"
 */
#[AsCommand(name: 'supply:staff', description: 'Показати або внести телефони з ролями')]
class SupplyStaffCommand extends Command
{
    public function __construct(
        private StaffPhoneRepository $repository,
        private StaffDirectory $directory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('phone', InputArgument::OPTIONAL, 'Телефон людини')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'worker | manager | director | admin', 'worker')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Чий це номер')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Примітка')
            ->addOption('remove', null, InputOption::VALUE_REQUIRED, 'Прибрати номер із довідника');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            if ($remove = $input->getOption('remove')) {
                $entry = $this->directory->lookup((string) $remove);

                if ($entry === null) {
                    $io->error('Такого номера в довіднику немає.');

                    return Command::FAILURE;
                }

                $this->directory->remove($entry);
                $io->success('Прибрано з довідника: ' . $entry->getPhone());

                return Command::SUCCESS;
            }

            $phone = (string) $input->getArgument('phone');

            if ($phone !== '') {
                $role = SupplyRole::tryFrom((string) $input->getOption('role'));

                if ($role === null) {
                    $io->error('Роль має бути worker, manager, director або admin.');

                    return Command::FAILURE;
                }

                $entry = $this->directory->save(
                    $phone,
                    $role,
                    $input->getOption('name'),
                    $input->getOption('note'),
                );

                $io->success(sprintf(
                    '%s — %s%s',
                    $entry->getPhone(),
                    $entry->getRole()->label(),
                    $entry->getAppliedTo() !== null ? ' (роль уже видано, людина в боті)' : ' (чекає на реєстрацію)',
                ));
            }
        } catch (SupplyException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $rows = [];

        foreach ($this->repository->findAllOrdered() as $entry) {
            $rows[] = [
                $entry->getPhone(),
                $entry->getName() ?? '—',
                $entry->getRole()->label(),
                $entry->getAppliedTo()?->displayName() ?? 'чекає',
            ];
        }

        $rows
            ? $io->table(['Телефон', 'Хто', 'Роль', 'Видано'], $rows)
            : $io->writeln('Довідник порожній.');

        return Command::SUCCESS;
    }
}
