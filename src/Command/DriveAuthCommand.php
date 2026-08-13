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
    /**
     * Google вимкнув «код у вікні браузера» (redirect_uri=urn:ietf:wg:oauth:2.0:oob)
     * ще у 2022-му, новий клієнт із ним отримає відмову. Лишається loopback:
     * після згоди браузер іде на цю адресу й показує «сайт недоступний», а код
     * лежить в адресному рядку — його людина й надсилає нам.
     *
     * Слухати порт не потрібно: код одноразовий, обмінюємо його ми самі.
     */
    private const REDIRECT_URI = 'http://localhost:53682';

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
            $io->writeln('Якщо клієнт створено як Web application — додайте ' . self::REDIRECT_URI . ' у Authorized redirect URIs.');

            return Command::FAILURE;
        }

        $client = new Client();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri(self::REDIRECT_URI);
        $client->setScopes([Drive::DRIVE_FILE]);
        // Без цих двох рядків Google віддає лише access-токен на годину.
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $io->section('Крок 1 — відкрийте посилання в браузері під акаунтом «Буддеталі»');
        $io->writeln($client->createAuthUrl());
        $io->newLine();
        $io->text([
            'Після згоди браузер відкриє ' . self::REDIRECT_URI . ' і напише, що сайт недоступний —',
            'так і має бути. Потрібна вся адреса з рядка браузера, у ній є ?code=…',
        ]);
        $io->newLine();

        $answer = $io->askQuestion(new Question('Крок 2 — вставте адресу (або сам код): '));
        $code = $this->extractCode((string) $answer);

        if ($code === null) {
            $io->error('Код не знайдено — потрібна адреса з ?code=… або сам код.');

            return Command::FAILURE;
        }

        $token = $client->fetchAccessTokenWithAuthCode($code);

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
        $io->note(
            'Якщо в Google Cloud Console → OAuth consent screen статус «Testing», токен помре через 7 днів. '
            . 'Перемкніть на «In production» — для scope drive.file перевірку Google проходити не треба.',
        );

        return Command::SUCCESS;
    }

    /** Приймаємо і повну адресу редіректу, і голий код — людині так простіше. */
    private function extractCode(string $answer): ?string
    {
        $answer = trim($answer);

        if ($answer === '') {
            return null;
        }

        $query = parse_url($answer, PHP_URL_QUERY);

        if (is_string($query)) {
            parse_str($query, $params);
            $answer = is_string($params['code'] ?? null) ? $params['code'] : '';
        }

        return $answer === '' ? null : $answer;
    }
}
