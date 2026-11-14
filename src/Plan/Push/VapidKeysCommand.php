<?php

namespace App\Plan\Push;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Нова пара VAPID-ключів для Web Push. Друкує два рядки для `.env.local`
 * Плану — нікуди їх не пише. Змінити ключі = усі підписки браузерів перестануть
 * діяти (люди підпишуться знову в налаштуваннях).
 */
#[AsCommand(name: 'plan:push:vapid-keys', description: 'Згенерувати VAPID-ключі для Web Push (друкує рядки для .env.local)')]
final class VapidKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keys = VAPID::createVapidKeys();
        $io = new SymfonyStyle($input, $output);
        $io->writeln('PLAN_VAPID_PUBLIC_KEY=' . $keys['publicKey']);
        $io->writeln('PLAN_VAPID_PRIVATE_KEY=' . $keys['privateKey']);
        $io->getErrorStyle()->note('Додайте обидва рядки в .env.local Плану (600, deploy:www-data) і зробіть cache:clear. Приватний ключ нікуди більше не копіюйте.');

        return Command::SUCCESS;
    }
}
