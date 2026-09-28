<?php

namespace App\Command;

use App\Supply\Repository\SupplyAttachmentRepository;
use App\Supply\Service\AttachFile;
use App\Supply\Service\GoogleDriveMirror;
use App\Warehouse\Repository\WhDocumentRepository;
use App\Warehouse\Service\DocumentStore;
use App\Warehouse\Service\WarehouseDriveMirror;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Відправляє в Google Drive документи, які туди ще не поїхали: заявок і складу.
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
        private WhDocumentRepository $warehouseDocuments,
        private WarehouseDriveMirror $warehouseMirror,
        private DocumentStore $warehouseStore,
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

        $limit = (int) $input->getOption('limit');

        // Склад іде тим самим кроном і тими самими ключами: другий крон на
        // стенді забули б так само, як забувають перший.
        $sources = [
            [
                $this->attachments->findNotMirrored($limit),
                $this->attachFile->read(...),
                $this->mirror->mirror(...),
            ],
            [
                $this->warehouseDocuments->findNotMirrored($limit),
                $this->warehouseStore->read(...),
                $this->warehouseMirror->mirror(...),
            ],
        ];

        $waiting = array_sum(array_map(static fn (array $source) => count($source[0]), $sources));

        // Крон вішається заздалегідь, ще до того, як у клієнта зʼявиться Диск:
        // щойно в оточення ляже токен, копіювання почнеться саме собою і про
        // нього не треба буде згадувати. Поки Диска немає, мовчимо — але рівно
        // доти, доки нічого не втрачаємо. Зʼявився документ без копії — це вже
        // варте рядка в лозі, інакше в ньому потоне справжня помилка.
        if (! $this->mirror->isEnabled()) {
            if ($waiting > 0) {
                $io->warning(sprintf(
                    'Google Drive не налаштований (немає GOOGLE_DRIVE_* в оточенні), а документів без копії: %d. Поки вони лише в нашому сховищі.',
                    $waiting,
                ));
            }

            return Command::SUCCESS;
        }

        if ($waiting === 0) {
            $io->success('Усі документи вже в Google Drive.');

            return Command::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($sources as [$pending, $read, $mirror]) {
            foreach ($pending as $document) {
                try {
                    $contents = $read($document);
                } catch (Throwable $e) {
                    $io->writeln(sprintf('  ✖ %s — файла немає у сховищі: %s', $document->getOriginalName(), $e->getMessage()));
                    ++$failed;

                    continue;
                }

                if ($mirror($document, $contents)) {
                    $io->writeln(sprintf('  ✔ %s → %s', $document->getOriginalName(), $document->getDriveUrl()));
                    ++$sent;
                } else {
                    $io->writeln(sprintf('  ✖ %s — не вдалось, деталі в лозі', $document->getOriginalName()));
                    ++$failed;
                }
            }
        }

        $io->newLine();
        $failed === 0
            ? $io->success(sprintf('Скопійовано: %d.', $sent))
            : $io->warning(sprintf('Скопійовано: %d, не вдалось: %d.', $sent, $failed));

        return Command::SUCCESS;
    }
}
