<?php

namespace App\Command;

use App\Supply\Entity\Department;
use App\Supply\Repository\DepartmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Довідник підрозділів. Окремого екрана в CRM поки немає, тому список
 * заводять цією командою: `supply:department "Цех №1" "Цех №2"`.
 */
#[AsCommand(name: 'supply:department', description: 'Показати або додати підрозділи')]
class SupplyDepartmentCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private DepartmentRepository $departments,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('names', InputArgument::IS_ARRAY, 'Назви підрозділів для додавання')
            ->addOption('disable', null, InputOption::VALUE_REQUIRED, 'Прибрати підрозділ зі списку вибору');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($name = $input->getOption('disable')) {
            $department = $this->departments->findOneBy(['name' => $name]);

            if ($department === null) {
                $io->error(sprintf('Підрозділ «%s» не знайдено.', $name));

                return Command::FAILURE;
            }

            // Не видаляємо: на підрозділ можуть посилатись старі заявки.
            $department->setActive(false);
            $this->em->flush();
            $io->success(sprintf('«%s» прибрано зі списку вибору.', $name));
        }

        foreach ($input->getArgument('names') as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $existing = $this->departments->findOneBy(['name' => $name]);

            if ($existing !== null) {
                $existing->setActive(true);
                $io->writeln(sprintf('· «%s» вже є', $name));
                continue;
            }

            $this->em->persist((new Department())->setName($name));
            $io->writeln(sprintf('+ «%s»', $name));
        }

        $this->em->flush();

        $rows = array_map(
            static fn (Department $d) => [$d->getId(), $d->getName(), $d->isActive() ? 'активний' : 'прихований'],
            $this->departments->findBy([], ['name' => 'ASC']),
        );

        $rows
            ? $io->table(['ID', 'Підрозділ', 'Стан'], $rows)
            : $io->warning('Підрозділів ще немає. Додайте: supply:department "Цех №1" "Цех №2"');

        return Command::SUCCESS;
    }
}
