<?php

namespace App\Command;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Service\AccessService;
use App\Service\CrmLoginLink;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Видати посилання на вхід у CRM з консолі.
 *
 * Потрібне, коли бот недоступний (збій Telegram) або для показу з чужого пристрою:
 * інакше єдиний шлях у CRM лежить через бота.
 */
#[AsCommand(name: 'supply:crm-link', description: 'Одноразове посилання на вхід у CRM за номером телефону')]
class CrmLinkCommand extends Command
{
    public function __construct(
        private TelegramUserRepository $users,
        private CrmLoginLink $loginLink,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('phone', InputArgument::REQUIRED, 'Телефон менеджера, наприклад 0633022666');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tail = substr(AccessService::normalize((string)$input->getArgument('phone')), -9);
        $user = null;

        foreach ($this->users->findAll() as $candidate) {
            if ($candidate->getPhoneNumber() !== null
                && str_ends_with(AccessService::normalize($candidate->getPhoneNumber()), $tail)) {
                $user = $candidate;
                break;
            }
        }

        if ($user === null) {
            $io->error('Користувача з таким номером немає — спершу зареєструйтесь у боті.');

            return Command::FAILURE;
        }

        if (!$user->getSupplyRole()->canManage()) {
            $io->error(sprintf('%s має роль «%s», доступу до CRM немає.', $user->displayName(), $user->getSupplyRole()->label()));

            return Command::FAILURE;
        }

        $io->success(sprintf('%s — %s', $user->displayName(), $user->getSupplyRole()->label()));
        $io->writeln($this->loginLink->issue($user));
        $io->writeln('<comment>Діє 5 хвилин, спрацьовує один раз.</comment>');

        return Command::SUCCESS;
    }
}
