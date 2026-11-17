<?php

namespace App\Plan\Zvyazok\Drive;

use App\Plan\Drive\DriveItem;
use App\Plan\Drive\DrivePaths;
use App\Plan\Drive\Source\DriveSource;
use App\Plan\Share\FileType;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Plan\Zvyazok\ZvyazokFiles;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Файли «Звʼязку» на Диск: План / <рік> / Звʼязок / №<id чату> <назва> / 2026-11-17 <файл>.
 * Видалене повідомлення (до копіювання) — skipped; вже скопійоване лишається на Диску,
 * як і у файлів задач.
 */
final readonly class ZvyazokDriveFiles implements DriveSource
{
    public function __construct(
        private Connection $db,
        private ZvyazokFiles $files,
        private DrivePaths $paths,
    ) {
    }

    public function type(): FileType
    {
        return FileType::Zvyazok;
    }

    public function item(int $id): ?DriveItem
    {
        $row = $this->db->fetchAssociative(
            'SELECT a.id, a.chat_id, a.original_name, a.mime, a.created_at, m.deleted_at, c.kind, c.title
               FROM plan_chat_attachment a JOIN plan_chat_message m ON m.id = a.message_id JOIN plan_chat c ON c.id = a.chat_id
              WHERE a.id = ?',
            [$id],
        );

        if ($row === false || $row['deleted_at'] !== null) {
            return null;
        }

        $chatId = (int) $row['chat_id'];
        $uploaded = new DateTimeImmutable((string) $row['created_at']);

        return new DriveItem(
            FileType::Zvyazok,
            $id,
            DrivePaths::dated($uploaded, (string) $row['original_name']),
            (string) $row['mime'],
            $this->paths->zvyazok($uploaded, $chatId, $row['kind'] === 'group' ? (string) $row['title'] : $this->directTitle($chatId)),
            SubjectRef::of(SubjectType::Chat, $chatId),
        );
    }

    public function read(int $id): string
    {
        $path = $this->db->fetchOne('SELECT path FROM plan_chat_attachment WHERE id = ?', [$id]);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Файл «Звʼязку» не знайдено.');
        }

        $contents = @file_get_contents($this->files->path($path));

        if ($contents === false) {
            throw new RuntimeException('Файла немає у сховищі «Звʼязку».');
        }

        return $contents;
    }

    /** «Особисте — Іван Шуба, Олексій Коваль» (імена за абеткою, щоб тека не залежала від того, хто почав). */
    private function directTitle(int $chatId): string
    {
        $names = $this->db->fetchFirstColumn(
            "SELECT TRIM(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')) FROM plan_chat_member m JOIN telegram_user u ON u.id = m.user_id WHERE m.chat_id = ? ORDER BY 1",
            [$chatId],
        );
        $names = array_values(array_filter(array_map('strval', $names), static fn (string $n) => $n !== ''));

        return 'Особисте — ' . ($names !== [] ? implode(', ', $names) : 'чат');
    }
}
