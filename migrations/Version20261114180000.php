<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «План», стрічка задачі: відповідь із цитатою, правка з історією, закріплення,
 * ★ повідомлень і задач, тип події для Хроніки; індекси пошуку (pg_trgm + FTS).
 */
final class Version20261114180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Задачі: відповідь/правка/закріплення/★ повідомлень, ★ задач, тип події для Хроніки, повнотекстовий і триграмний пошук';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE task_favorite_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE task_message_edit_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE task_message_star_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE task_favorite (id INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, task_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_task_favorite ON task_favorite (user_id, task_id)');
        $this->addSql('CREATE INDEX IDX_280564958DB60186 ON task_favorite (task_id)');
        $this->addSql('CREATE INDEX IDX_28056495A76ED395 ON task_favorite (user_id)');
        $this->addSql('CREATE TABLE task_message_edit (id INT NOT NULL, old_text TEXT NOT NULL, edited_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, message_id INT NOT NULL, editor_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_task_message_edit_message ON task_message_edit (message_id)');
        $this->addSql('CREATE INDEX IDX_E03161EE6995AC4C ON task_message_edit (editor_id)');
        $this->addSql('CREATE TABLE task_message_star (id INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, message_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_task_message_star ON task_message_star (user_id, message_id)');
        $this->addSql('CREATE INDEX IDX_A8DFE5E0537A1329 ON task_message_star (message_id)');
        $this->addSql('CREATE INDEX IDX_A8DFE5E0A76ED395 ON task_message_star (user_id)');
        $this->addSql('ALTER TABLE task_favorite ADD CONSTRAINT FK_280564958DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_favorite ADD CONSTRAINT FK_28056495A76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_message_edit ADD CONSTRAINT FK_E03161EE537A1329 FOREIGN KEY (message_id) REFERENCES task_message (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_message_edit ADD CONSTRAINT FK_E03161EE6995AC4C FOREIGN KEY (editor_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_message_star ADD CONSTRAINT FK_A8DFE5E0537A1329 FOREIGN KEY (message_id) REFERENCES task_message (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_message_star ADD CONSTRAINT FK_A8DFE5E0A76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_message ADD event_type VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_message ADD edited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE task_message ADD pinned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE task_message ADD changed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE task_message ADD reply_to_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE task_message ADD pinned_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE task_message ADD CONSTRAINT FK_A95C1A95FFDF7169 FOREIGN KEY (reply_to_id) REFERENCES task_message (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_message ADD CONSTRAINT FK_A95C1A9559662AC1 FOREIGN KEY (pinned_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A95C1A95FFDF7169 ON task_message (reply_to_id)');
        $this->addSql('CREATE INDEX IDX_A95C1A9559662AC1 ON task_message (pinned_by_id)');

        // Тип для вже записаних системних подій — за текстом, який їх писав.
        $this->addSql("UPDATE task_message SET event_type = 'created' WHERE kind = 'event' AND text LIKE 'створив(ла) задачу%'");
        $this->addSql("UPDATE task_message SET event_type = 'closed' WHERE kind = 'event' AND text LIKE 'змінив(ла) статус:%' AND (text LIKE '%→ Виконана' OR text LIKE '%→ Відхилено')");
        $this->addSql("UPDATE task_message SET event_type = 'status' WHERE kind = 'event' AND event_type IS NULL AND text LIKE 'змінив(ла) статус:%'");
        $this->addSql("UPDATE task_message SET event_type = 'members' WHERE kind = 'event' AND text LIKE 'додав(ла) учасників:%'");
        $this->addSql("UPDATE task_message SET event_type = 'updated' WHERE kind = 'event' AND event_type IS NULL");

        // Пошук: триграми — «за частиною слова» (lower(x) LIKE '%плит%'; індекс
        // по виразу lower() — його ж бере й пошук дошки, а Doctrine такі не чіпає), FTS 'simple' —
        // ранжування цілих слів і префіксів без мовного словника (українська в
        // PostgreSQL не має вбудованого стемера, 'simple' лише приводить регістр).
        // pg_trgm — довірене розширення (PG 13+): власник бази може його ввімкнути.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE INDEX idx_task_title_trgm ON task USING gin (lower(title) gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_task_client_trgm ON task USING gin (lower(client) gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_task_description_trgm ON task USING gin (lower(description) gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_task_message_text_trgm ON task_message USING gin (lower(text) gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_task_attachment_name_trgm ON task_attachment USING gin (lower(original_name) gin_trgm_ops)');
        $this->addSql("CREATE INDEX idx_task_fts ON task USING gin (to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(client, '') || ' ' || coalesce(description, '')))");
        $this->addSql("CREATE INDEX idx_task_message_fts ON task_message USING gin (to_tsvector('simple', text))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_task_message_fts');
        $this->addSql('DROP INDEX idx_task_fts');
        $this->addSql('DROP INDEX idx_task_attachment_name_trgm');
        $this->addSql('DROP INDEX idx_task_message_text_trgm');
        $this->addSql('DROP INDEX idx_task_description_trgm');
        $this->addSql('DROP INDEX idx_task_client_trgm');
        $this->addSql('DROP INDEX idx_task_title_trgm');
        $this->addSql('ALTER TABLE task_message DROP CONSTRAINT FK_A95C1A95FFDF7169');
        $this->addSql('ALTER TABLE task_message DROP CONSTRAINT FK_A95C1A9559662AC1');
        $this->addSql('DROP INDEX IDX_A95C1A95FFDF7169');
        $this->addSql('DROP INDEX IDX_A95C1A9559662AC1');
        $this->addSql('ALTER TABLE task_message DROP event_type');
        $this->addSql('ALTER TABLE task_message DROP edited_at');
        $this->addSql('ALTER TABLE task_message DROP pinned_at');
        $this->addSql('ALTER TABLE task_message DROP changed_at');
        $this->addSql('ALTER TABLE task_message DROP reply_to_id');
        $this->addSql('ALTER TABLE task_message DROP pinned_by_id');
        $this->addSql('DROP TABLE task_favorite');
        $this->addSql('DROP TABLE task_message_edit');
        $this->addSql('DROP TABLE task_message_star');
        $this->addSql('DROP SEQUENCE task_favorite_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE task_message_edit_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE task_message_star_id_seq CASCADE');
    }
}
