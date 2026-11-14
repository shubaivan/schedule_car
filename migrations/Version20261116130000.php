<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Колонка дошки «Мої повідомлення» (TaskRepository::myMessages) шукає згадки
 * `mentions::jsonb @> [me]` — без індексу це паралельний скан усього task_message
 * на кожне оновлення дошки (DB-perf #4: 105 → 67 мс на 50k задач).
 *
 * Частковий GIN лише по живих коментарях; CONCURRENTLY — без блокування запису,
 * тож міграція не транзакційна. IF NOT EXISTS — повторний прогін не падає.
 */
final class Version20261116130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: GIN-індекс згадок у коментарях задач (колонка «Мої повідомлення»)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_task_message_mentions ON task_message USING gin ((mentions::jsonb) jsonb_path_ops) WHERE kind = 'comment' AND deleted_at IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_task_message_mentions');
    }
}
