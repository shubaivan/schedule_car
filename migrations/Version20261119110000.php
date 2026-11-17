<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Звʼязок» (ТЗ §21, Олександр 15.11 і 17.11): особисті й групові чати поза задачами.
 * Нові таблиці, лише додає. До погодження Олександром модуль бачать лише люди з повним
 * доступом (PlanSections); PLAN_ZVYAZOK_FOR_ALL=1 — відкрити всім.
 */
final class Version20261119110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: Звʼязок — plan_chat, plan_chat_member, plan_chat_message, plan_chat_message_edit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE plan_chat_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE plan_chat_message_edit_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE plan_chat_message_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE plan_chat (id INT NOT NULL, kind VARCHAR(8) NOT NULL, title VARCHAR(120) DEFAULT NULL, direct_key VARCHAR(32) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_704B3E5898305898 ON plan_chat (direct_key)');
        $this->addSql('CREATE INDEX IDX_704B3E58DE12AB56 ON plan_chat (created_by)');
        $this->addSql('CREATE TABLE plan_chat_member (role VARCHAR(8) DEFAULT \'member\' NOT NULL, pinned BOOLEAN DEFAULT false NOT NULL, archived BOOLEAN DEFAULT false NOT NULL, last_read_id INT DEFAULT 0 NOT NULL, joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, left_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, chat_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (chat_id, user_id))');
        $this->addSql('CREATE INDEX idx_plan_chat_member_user ON plan_chat_member (user_id)');
        $this->addSql('CREATE INDEX IDX_E38115321A9A7125 ON plan_chat_member (chat_id)');
        $this->addSql('CREATE TABLE plan_chat_message_edit (id INT NOT NULL, old_body TEXT NOT NULL, edited_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, message_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_plan_chat_message_edit_msg ON plan_chat_message_edit (message_id)');
        $this->addSql('CREATE TABLE plan_chat_message (id INT NOT NULL, body TEXT NOT NULL, mentions JSONB DEFAULT \'[]\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, edited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, chat_id INT NOT NULL, author_id INT NOT NULL, reply_to_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_plan_chat_message_chat ON plan_chat_message (chat_id, id)');
        $this->addSql('CREATE INDEX IDX_2027FD1E1A9A7125 ON plan_chat_message (chat_id)');
        $this->addSql('CREATE INDEX IDX_2027FD1EF675F31B ON plan_chat_message (author_id)');
        $this->addSql('CREATE INDEX IDX_2027FD1EFFDF7169 ON plan_chat_message (reply_to_id)');
        $this->addSql('ALTER TABLE plan_chat ADD CONSTRAINT FK_704B3E58DE12AB56 FOREIGN KEY (created_by) REFERENCES telegram_user (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plan_chat_member ADD CONSTRAINT FK_E38115321A9A7125 FOREIGN KEY (chat_id) REFERENCES plan_chat (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plan_chat_member ADD CONSTRAINT FK_E3811532A76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plan_chat_message_edit ADD CONSTRAINT FK_DF4A3D5F537A1329 FOREIGN KEY (message_id) REFERENCES plan_chat_message (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plan_chat_message ADD CONSTRAINT FK_2027FD1E1A9A7125 FOREIGN KEY (chat_id) REFERENCES plan_chat (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plan_chat_message ADD CONSTRAINT FK_2027FD1EF675F31B FOREIGN KEY (author_id) REFERENCES telegram_user (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plan_chat_message ADD CONSTRAINT FK_2027FD1EFFDF7169 FOREIGN KEY (reply_to_id) REFERENCES plan_chat_message (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE plan_chat_message_edit');
        $this->addSql('DROP TABLE plan_chat_message');
        $this->addSql('DROP TABLE plan_chat_member');
        $this->addSql('DROP TABLE plan_chat');
        $this->addSql('DROP SEQUENCE plan_chat_message_edit_id_seq');
        $this->addSql('DROP SEQUENCE plan_chat_message_id_seq');
        $this->addSql('DROP SEQUENCE plan_chat_id_seq');
    }
}
