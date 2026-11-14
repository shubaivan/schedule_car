<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «План», інтеграція v2 — індекси, яких потребують S6 і S5 разом із рештою:
 *
 * - idx_plan_event_dedupe: AuditThrottle (S6) перед кожним записом скачування /
 *   перегляду шукає «та сама людина × тип × суб'єкт за 10 хв» — без індексу це
 *   скан усіх подій людини (idx_plan_event_actor), а журнал росте роками;
 * - idx_*_drive_queue: черга plan:drive-sync (S5) — «ще не на Диску й чекає» у
 *   трьох таблицях файлів; частковий індекс маленький (лише черга), done-рядки
 *   в нього не потрапляють.
 *
 * PG14-безпечно: звичайні CREATE INDEX у транзакції міграції (таблиці на стендах малі),
 * IF NOT EXISTS — повторний прогін не падає.
 */
final class Version20261115170000 extends AbstractMigration
{
    private const array FILE_TABLES = ['task_attachment', 'supply_attachment', 'wh_document'];

    public function getDescription(): string
    {
        return 'План v2: індекс зведення повторів аудиту (plan_event) і часткові індекси черги Диска';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_plan_event_dedupe ON plan_event (actor_id, type, subject_type, subject_id, occurred_at) WHERE actor_id IS NOT NULL');

        foreach (self::FILE_TABLES as $table) {
            $this->addSql(sprintf("CREATE INDEX IF NOT EXISTS idx_%s_drive_queue ON %s (id) WHERE drive_file_id IS NULL AND drive_state = 'pending'", $table, $table));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_plan_event_dedupe');

        foreach (self::FILE_TABLES as $table) {
            $this->addSql(sprintf('DROP INDEX IF EXISTS idx_%s_drive_queue', $table));
        }
    }
}
