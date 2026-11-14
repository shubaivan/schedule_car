<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ревью БД 15.11: зайві індекси (кожен — зайвий запис на кожну вставку/оновлення) і
 * один потрібний.
 *
 * Прибираємо (на проді idx_scan = 0 або індекс повністю покритий іншим, у коді не вжито;
 * doctrine:schema:update після видалення їх назад не просить):
 * - legacy-пошук до search_document (Version20261114180000): idx_task_message_text_trgm,
 *   idx_task_message_fts, idx_task_fts, idx_task_attachment_name_trgm — ні LIKE, ні
 *   to_tsvector по цих колонках у коді немає (пошук — лише search_document);
 * - покриті провідною колонкою іншого індексу (Doctrine створив їх для FK):
 *   idx_7dbd0bac71f7e88b plan_event_consumed(event_id) ⊂ PK (event_id, consumer),
 *   idx_68084f5b10daf24a plan_event(actor_id) ⊂ idx_plan_event_actor (actor_id, id),
 *   idx_bf5476caa76ed395 notification(user_id) ⊂ uniq_notification_user_event (user_id, event_id),
 *   idx_a95c1a958db60186 task_message(task_id) ⊂ idx_task_message_task (task_id, id).
 * Триграмні індекси task (title/description/client) лишаються — ними користується фільтр q дошки.
 *
 * Додаємо idx_plan_event_type_at (type, occurred_at): нічний plan:audit:prune видаляє
 * службові типи старші за строк — без нього це скан журналу.
 *
 * CONCURRENTLY — без блокування записів, тому міграція не транзакційна; IF [NOT] EXISTS —
 * повторний прогін і стенди, де частини індексів немає, не падають.
 */
final class Version20261116090000 extends AbstractMigration
{
    private const array DROP = [
        'idx_task_message_text_trgm',
        'idx_task_message_fts',
        'idx_task_fts',
        'idx_task_attachment_name_trgm',
        'idx_7dbd0bac71f7e88b',
        'idx_68084f5b10daf24a',
        'idx_bf5476caa76ed395',
        'idx_a95c1a958db60186',
    ];

    public function getDescription(): string
    {
        return 'План: прибрати невживані/покриті індекси, додати plan_event (type, occurred_at) для ретеншну';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (self::DROP as $index) {
            $this->addSql(sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $index));
        }

        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_plan_event_type_at ON plan_event (type, occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_plan_event_type_at');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_a95c1a958db60186 ON task_message (task_id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bf5476caa76ed395 ON notification (user_id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_68084f5b10daf24a ON plan_event (actor_id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_7dbd0bac71f7e88b ON plan_event_consumed (event_id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_task_message_text_trgm ON task_message USING gin (lower(text) gin_trgm_ops)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_task_attachment_name_trgm ON task_attachment USING gin (lower(original_name) gin_trgm_ops)');
        $this->addSql("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_task_fts ON task USING gin (to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(client, '') || ' ' || coalesce(description, '')))");
        $this->addSql("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_task_message_fts ON task_message USING gin (to_tsvector('simple', text))");
    }
}
