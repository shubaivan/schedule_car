<?php

namespace App\Tasks\Command;

use App\Tasks\Repository\TaskAttachmentRepository;
use App\Tasks\Service\PreviewGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Доробити превʼю, які не встигли після відповіді (зайнятий LibreOffice,
 * перезапуск php-fpm). Під крон раз на хвилину:
 *
 *     php bin/console tasks:previews
 *
 * Офісні файли конвертуються по одному (flock у PreviewGenerator), тож два
 * запуски поспіль не навантажать сервер удвічі.
 */
#[AsCommand(name: 'tasks:previews', description: 'Згенерувати превʼю вкладень задач, що чекають')]
class TasksPreviewsCommand extends Command
{
    public function __construct(
        private TaskAttachmentRepository $attachments,
        private PreviewGenerator $generator,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише показати, що чекає');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Скільки файлів за запуск', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pending = $this->attachments->pending((int) $input->getOption('limit'));

        foreach ($pending as $attachment) {
            if ($input->getOption('dry-run')) {
                $output->writeln(sprintf('#%d %s (%s)', $attachment->getId(), $attachment->getOriginalName(), $attachment->getKind()));

                continue;
            }

            $done = $this->generator->generate($attachment);
            $this->em->flush();
            $output->writeln(sprintf('#%d %s → %s', $attachment->getId(), $attachment->getOriginalName(), $done ? $attachment->getPreviewState() : 'зайнято, наступного разу'));
        }

        return Command::SUCCESS;
    }
}
