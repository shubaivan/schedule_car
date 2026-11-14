<?php

namespace App\Plan\Telegram;

use SergiX44\Nutgram\Nutgram;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Вебхук бота «Плану»: `<APP_BASE_URL>/hook` разом із secret_token
 * (TELEGRAM_WEBHOOK_SECRET), який перевіряє TelegramWebhookController.
 * `nutgram:hook:set` секрету не передає — тому окрема команда. Токен і секрет
 * беруться з .env.local і нікуди не друкуються.
 */
#[AsCommand(name: 'plan:bot:webhook', description: 'Поставити вебхук бота Плану з секретом (TELEGRAM_WEBHOOK_SECRET)')]
final class PlanBotWebhookCommand extends Command
{
    public function __construct(
        private readonly Nutgram $bot,
        private readonly PlanBotMode $mode,
        #[Autowire('%env(APP_BASE_URL)%')]
        private readonly string $baseUrl,
        #[Autowire('%env(default::TELEGRAM_WEBHOOK_SECRET)%')]
        private readonly ?string $secret = null,
        #[Autowire('%env(default::TELEGRAM_TOKEN)%')]
        private readonly ?string $token = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише показати, що буде зроблено');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $url = rtrim($this->baseUrl, '/') . '/hook';

        if (trim((string) $this->token) === '' || trim((string) $this->secret) === '') {
            $io->error('Потрібні TELEGRAM_TOKEN і TELEGRAM_WEBHOOK_SECRET у .env.local (без секрету вебхук відкритий будь-кому).');

            return Command::FAILURE;
        }

        if (! str_starts_with($url, 'https://')) {
            $io->error('Telegram приймає лише https-вебхук: ' . $url);

            return Command::FAILURE;
        }

        if (! $this->mode->enabled()) {
            $io->warning('PLAN_TELEGRAM_BOT не ввімкнено — бот працюватиме як бот заявок.');
        }

        if ($input->getOption('dry-run')) {
            $io->writeln('dry-run: setWebhook ' . $url . ' (secret_token з TELEGRAM_WEBHOOK_SECRET, allowed_updates: message, callback_query)');

            return Command::SUCCESS;
        }

        $this->bot->setWebhook($url, allowed_updates: ['message', 'callback_query'], drop_pending_updates: true, secret_token: (string) $this->secret);
        $io->success('Вебхук: ' . $url);

        return Command::SUCCESS;
    }
}
