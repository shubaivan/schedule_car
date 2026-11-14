<?php

namespace App\Plan\Drive;

use App\Plan\Share\FileType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Воркер `messenger:consume async`: копіює щойно додані файли; збій — файл лишається в черзі крону. */
#[AsMessageHandler]
final readonly class SyncDriveFilesHandler
{
    public function __construct(private DriveSync $sync, private DriveClientFactory $drives)
    {
    }

    public function __invoke(SyncDriveFiles $message): void
    {
        $type = FileType::tryFrom($message->type);
        $client = $this->drives->client();

        if ($type === null || $client === null || ! $this->drives->isPlanLayout() || $message->ids === []) {
            return;
        }

        $this->sync->run($client, count($message->ids), only: $type, ids: array_map('intval', $message->ids));
    }
}
