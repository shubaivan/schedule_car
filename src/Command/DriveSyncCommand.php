<?php

namespace App\Command;

use App\Supply\Repository\SupplyAttachmentRepository;
use App\Supply\Service\AttachFile;
use App\Supply\Service\GoogleDriveMirror;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Відправляє в Google Drive документи, які туди ще не поїхали.
 *
 * Окремим кроком, а не під час завантаження: якщо Google відповідає помилкою
 * чи мовчить, менеджер усе одно бачить, що файл прийнято, — копія доїде
 * наступним запуском. Вішається на крон раз на кілька хвилин.
 */
#[AsCommand(name: 'supply:drive-sync', description: 'Скопіювати документи заявок у Google Drive')]
class DriveSyncCommand extends Command
{
    public function __construct(
        private SupplyAttachmentRepository $attachments,
        private GoogleDriveMirror $mirror,
        private AttachFile $attachFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Скільки файлів за раз', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (! $this->mirror->isEnabled()) {
            $io->warning('Google Drive не налаштований: немає GOOGLE_DRIVE_* в оточенні. Файли лишаються тільки в нашому сховищі.');

            return Command::SUCCESS;
        }

        $pending = $this->attachments->findNotMirrored((int) $input->getOption('limit'));

        if (! $pending) {
            $io->success('Усі документи вже в Google Drive.');

            return Command::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($pending as $attachment) {
            try {
                $contents = $this->attachFile->read($attachment);
            } catch (Throwable $e) {
                $io->writeln(sprintf('  ✖ %s — файла немає у сховищі: %s', $attachment->getOriginalName(), $e->getMessage()));
                ++$failed;

                continue;
            }

            if ($this->mirror->mirror($attachment, $contents)) {
                $io->writeln(sprintf('  ✔ %s → %s', $attachment->getOriginalName(), $attachment->getDriveUrl()));
                ++$sent;
            } else {
                $io->writeln(sprintf('  ✖ %s — не вдалось, деталі в лозі', $attachment->getOriginalName()));
                ++$failed;
            }
        }

        $io->newLine();
        $failed === 0
            ? $io->success(sprintf('Скопійовано: %d.', $sent))
            : $io->warning(sprintf('Скопійовано: %d, не вдалось: %d.', $sent, $failed));

        return Command::SUCCESS;
    }
}
