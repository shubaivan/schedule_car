<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Звʼязок»: файли в повідомленнях (Олександр 17.11) — нова таблиця plan_chat_attachment
 * з колонками копії на Google Диску (як у task_attachment). Старі таблиці не змінюються.
 */
final class Version20261119130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: Звʼязок — plan_chat_attachment (фото, документи, PDF; копія на Диску)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE plan_chat_attachment_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql("CREATE TABLE plan_chat_attachment (id INT NOT NULL, original_name VARCHAR(255) NOT NULL, mime VARCHAR(127) NOT NULL, size INT NOT NULL, original_size INT DEFAULT 0 NOT NULL, sha256 VARCHAR(64) NOT NULL, path VARCHAR(255) DEFAULT '' NOT NULL, kind VARCHAR(10) NOT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, drive_file_id VARCHAR(128) DEFAULT NULL, drive_url VARCHAR(512) DEFAULT NULL, drive_state VARCHAR(8) DEFAULT 'pending' NOT NULL, drive_attempts INT DEFAULT 0 NOT NULL, drive_error TEXT DEFAULT NULL, drive_next_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, message_id INT NOT NULL, chat_id INT NOT NULL, uploaded_by_id INT DEFAULT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE INDEX idx_plan_chat_attachment_message ON plan_chat_attachment (message_id)');
        $this->addSql('CREATE INDEX idx_plan_chat_attachment_chat ON plan_chat_attachment (chat_id)');
        $this->addSql('CREATE INDEX IDX_5EC4AB63A2B28FE8 ON plan_chat_attachment (uploaded_by_id)');
        $this->addSql("CREATE INDEX idx_plan_chat_attachment_drive_queue ON plan_chat_attachment (id) WHERE ((drive_file_id IS NULL) AND ((drive_state)::text = 'pending'::text))");
        $this->addSql('ALTER TABLE plan_chat_attachment ADD CONSTRAINT FK_PLAN_CHAT_ATT_MESSAGE FOREIGN KEY (message_id) REFERENCES plan_chat_message (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plan_chat_attachment ADD CONSTRAINT FK_PLAN_CHAT_ATT_CHAT FOREIGN KEY (chat_id) REFERENCES plan_chat (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plan_chat_attachment ADD CONSTRAINT FK_PLAN_CHAT_ATT_UPLOADER FOREIGN KEY (uploaded_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE plan_chat_attachment');
        $this->addSql('DROP SEQUENCE plan_chat_attachment_id_seq');
    }
}
