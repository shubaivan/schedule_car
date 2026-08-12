<?php

namespace App\Command;

use Google\Client;
use Google\Service\Drive;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Разова процедура: отримати refresh-токен Google Drive від імені акаунта
 * заводу. Далі система працює сама, без участі людини.
 *
 * Робиться один раз під акаунтом «Буддеталі»: файли лежатимуть у ЙОГО диску
 * і належатимуть заводу, а не розробнику й не сервісному акаунту.
 */
#[AsCommand(name: 'supply:drive-auth', description: 'Отримати refresh-токен Google Drive')]
class DriveAuthCommand extends Command
{
    public function __construct(
        #[Autowire('%env(GOOGLE_DRIVE_CLIENT_ID)%')]
        private string $clientId,
        #[Autowire('%env(GOOGLE_DRIVE_CLIENT_SECRET)%')]
        private string $clientSecret,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->clientId === '' || $this->clientSecret === '') {
            $io->error('Спершу задайте GOOGLE_DRIVE_CLIENT_ID і GOOGLE_DRIVE_CLIENT_SECRET у .env.local.');
            $io->writeln('Їх видає Google Cloud Console → APIs & Services → Credentials → OAuth client ID (тип Desktop app).');

            return Command::FAILURE;
        }

        $client = new Client();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        // «з коробки» для Desktop-клієнта: код показується у вікні браузера.
        $client->setRedirectUri('urn:ietf:wg:oauth:2.0:oob');
        $client->setScopes([Drive::DRIVE_FILE]);
        // Без цих двох рядків Google віддає лише access-токен на годину.
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $io->section('Крок 1 — відкрийте посилання в браузері під акаунтом «Буддеталі»');
        $io->writeln($client->createAuthUrl());
        $io->newLine();

        $code = $io->askQuestion(new Question('Крок 2 — вставте код, який показав Google: '));

        if (! $code) {
            $io->error('Код не введено.');

            return Command::FAILURE;
        }

        $token = $client->fetchAccessTokenWithAuthCode(trim((string) $code));

        if (isset($token['error'])) {
            $io->error(sprintf('Google відмовив: %s', $token['error_description'] ?? $token['error']));

            return Command::FAILURE;
        }

        if (empty($token['refresh_token'])) {
            $io->error('Google не віддав refresh_token. Приберіть доступ застосунку в акаунті та повторіть.');

            return Command::FAILURE;
        }

        $io->success('Готово. Додайте рядок у .env.local на сервері:');
        $io->writeln('GOOGLE_DRIVE_REFRESH_TOKEN=' . $token['refresh_token']);
        $io->newLine();
        $io->warning('Це ключ від диску заводу — не комітьте його і не надсилайте в чати.');

        return Command::SUCCESS;
    }
}
