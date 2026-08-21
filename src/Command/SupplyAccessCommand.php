<?php

namespace App\Command;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Repository\TelegramUserRepository;
use App\Service\AccessService;
use App\Supply\Enum\SupplyRole;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Відкрити людині доступ із консолі:
 *
 *     php bin/console supply:access Vladislav_Oliinik --role=manager
 *
 * Кнопка «підтвердити» живе в боті й приходить менеджеру одним повідомленням у
 * момент реєстрації. Якщо те повідомлення загубилось у чаті — а на заводі так
 * і буває — людина лишається в «очікує» назавжди, і зрушити її нема чим:
 * у CRM підтвердження немає. Ця команда і є той запасний шлях.
 */
#[AsCommand(name: 'supply:access', description: 'Підтвердити або відхилити реєстрацію людини')]
class SupplyAccessCommand extends Command
{
    public function __construct(
        private TelegramUserRepository $users,
        private AccessService $access,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('who', InputArgument::REQUIRED, 'Username, телефон або id користувача')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'worker | manager | director | admin')
            ->addOption('reject', null, InputOption::VALUE_NONE, 'Відмовити замість підтвердити')
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'Хто вирішив: username або id (типово — перший адмін)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = $this->find((string) $input->getArgument('who'));

        if ($user === null) {
            $io->error('Такої людини в боті немає. Вона має спершу натиснути «Старт».');

            return Command::FAILURE;
        }

        $by = $this->decider($input->getOption('by'));

        if ($by === null) {
            $io->error('Немає від чийого імені вирішувати: вкажіть --by.');

            return Command::FAILURE;
        }

        $role = $input->getOption('role');

        if ($role !== null) {
            $parsed = SupplyRole::tryFrom((string) $role);

            if ($parsed === null) {
                $io->error('Невідома роль: ' . $role);

                return Command::FAILURE;
            }

            $user->setSupplyRole($parsed);
        }

        $status = $input->getOption('reject') ? AccessStatus::Rejected : AccessStatus::Approved;

        // Через AccessService, а не руками в базу: він і рішення запише, і
        // напише людині в чат, що доступ відкрито, разом із головним меню.
        $this->access->decide($user, $status, $by);

        $io->success(sprintf(
            '%s — %s, роль: %s. Рішення від імені %s.',
            $user->displayName(),
            $status === AccessStatus::Approved ? 'доступ відкрито' : 'відмовлено',
            $user->getSupplyRole()->label(),
            $by->displayName(),
        ));

        return Command::SUCCESS;
    }

    /**
     * Людину шукаємо як зручно: @username, номер телефону або id.
     *
     * Розрізняємо не «чи є цифри» — username на кшталт «Vlad2024» їх теж має, —
     * а форму запису: телефон складається лише з цифр і розділових знаків.
     */
    private function find(string $who): ?TelegramUser
    {
        $who = trim($who);

        if (str_starts_with($who, '@')) {
            return $this->users->findOneBy(['username' => ltrim($who, '@')]);
        }

        if (preg_match('/^[\d\s+()-]+$/', $who) !== 1) {
            return $this->users->findOneBy(['username' => $who]);
        }

        $digits = AccessService::normalize($who);

        return strlen($digits) < 7
            ? $this->users->find((int) $digits)
            : $this->users->findOneByPhoneTail(substr($digits, -9));
    }

    private function decider(?string $by): ?TelegramUser
    {
        if ($by !== null) {
            return $this->find($by);
        }

        return $this->users->findOneBy(['supplyRole' => SupplyRole::Admin], ['id' => 'ASC']);
    }
}
