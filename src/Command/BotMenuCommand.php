<?php

namespace App\Command;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Command\BotCommand;
use SergiX44\Nutgram\Telegram\Types\Command\BotCommandScopeDefault;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Публікує список команд у меню бота (кнопка «Menu» біля поля вводу),
 * щоб вхід у CRM не доводилось шукати серед інлайн-кнопок.
 */
#[AsCommand(name: 'bot:menu', description: 'Оновити меню команд бота в Telegram')]
class BotMenuCommand extends Command
{
    private const COMMANDS = [
        'start' => 'Головне меню',
        'postachannia' => 'Постачання: заявки на матеріали',
        'avtopark' => 'Автопарк: розклад машин і бронювання',
        'crm' => 'Вхід у CRM (для менеджерів)',
    ];

    public function __construct(private Nutgram $bot)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $commands = [];
        foreach (self::COMMANDS as $command => $description) {
            $commands[] = BotCommand::make($command, $description);
        }

        // Scope обов'язковий: із null Telegram відповідає «BotCommandScope must be an Object».
        $this->bot->setMyCommands($commands, new BotCommandScopeDefault());

        $io->success('Меню бота оновлено:');
        foreach (self::COMMANDS as $command => $description) {
            $io->writeln(sprintf('  /%s — %s', $command, $description));
        }

        return Command::SUCCESS;
    }
}
