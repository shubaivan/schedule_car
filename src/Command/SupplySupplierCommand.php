<?php

namespace App\Command;

use App\Supply\Entity\Supplier;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplierRepository;
use App\Supply\Service\SupplierDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Довідник постачальників із консолі — щоб залити початковий список на стенд
 * до того, як менеджер зайде в CRM: `supply:supplier "ФОП Петренко О.П."`.
 */
#[AsCommand(name: 'supply:supplier', description: 'Показати або додати постачальників')]
class SupplySupplierCommand extends Command
{
    public function __construct(
        private SupplierRepository $suppliers,
        private SupplierDirectory $directory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('names', InputArgument::IS_ARRAY, 'Назви постачальників для додавання')
            ->addOption('edrpou', null, InputOption::VALUE_REQUIRED, 'ЄДРПОУ/ІПН — лише разом з однією назвою')
            ->addOption('phone', null, InputOption::VALUE_REQUIRED, 'Телефон — лише разом з однією назвою')
            ->addOption('disable', null, InputOption::VALUE_REQUIRED, 'Прибрати постачальника зі списку вибору')
            ->addOption('enable', null, InputOption::VALUE_REQUIRED, 'Повернути постачальника у список вибору');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $names = array_filter(array_map('trim', $input->getArgument('names')));
        $extra = array_filter([
            'edrpou' => $input->getOption('edrpou'),
            'phone' => $input->getOption('phone'),
        ]);

        if ($extra !== [] && count($names) !== 1) {
            $io->error('ЄДРПОУ і телефон стосуються одного постачальника — вкажіть рівно одну назву.');

            return Command::FAILURE;
        }

        foreach (['disable' => false, 'enable' => true] as $option => $active) {
            $name = $input->getOption($option);

            if (! $name) {
                continue;
            }

            $supplier = $this->suppliers->findOneByName($name);

            if ($supplier === null) {
                $io->error(sprintf('Постачальника «%s» не знайдено.', $name));

                return Command::FAILURE;
            }

            $this->directory->update($supplier, ['active' => $active]);
            $io->success(sprintf('«%s» — %s.', $supplier->getName(), $active ? 'у списку' : 'прихований'));
        }

        foreach ($names as $name) {
            try {
                $supplier = $this->directory->create($name, null, $extra);
                $io->writeln(sprintf('+ «%s»', $supplier->getName()));
            } catch (SupplyException $e) {
                $io->writeln(sprintf('· %s', $e->getMessage()));
            }
        }

        $rows = array_map(
            static fn (Supplier $s) => [
                $s->getId(),
                $s->getName(),
                $s->getEdrpou() ?? '—',
                $s->getPhone() ?? '—',
                $s->isActive() ? 'активний' : 'прихований',
            ],
            $this->suppliers->findBy([], ['name' => 'ASC']),
        );

        $rows
            ? $io->table(['ID', 'Постачальник', 'ЄДРПОУ', 'Телефон', 'Стан'], $rows)
            : $io->warning('Постачальників ще немає. Додайте: supply:supplier "ФОП Петренко О.П."');

        return Command::SUCCESS;
    }
}
