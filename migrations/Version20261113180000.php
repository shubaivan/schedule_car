<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Задачі (аналог ПланФікса): задача, учасники, відділи, мітки, чат, файли з превʼю, прочитане; співробітник — пароль, email, посада, роль, кошик';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE task_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE task_attachment_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE task_label_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE task_member_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE task_message_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE task_read_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE task (id INT NOT NULL, creator_id INT NOT NULL, title VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, client VARCHAR(255) DEFAULT NULL, status VARCHAR(16) DEFAULT \'new\' NOT NULL, due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_task_status ON task (status)');
        $this->addSql('CREATE INDEX idx_task_activity ON task (last_activity_at)');
        $this->addSql('CREATE INDEX IDX_527EDB2561220EA6 ON task (creator_id)');
        $this->addSql('CREATE TABLE task_department (task_id INT NOT NULL, department_id INT NOT NULL, PRIMARY KEY(task_id, department_id))');
        $this->addSql('CREATE INDEX IDX_D10EAA258DB60186 ON task_department (task_id)');
        $this->addSql('CREATE INDEX IDX_D10EAA25AE80F5DF ON task_department (department_id)');
        $this->addSql('CREATE TABLE task_task_label (task_id INT NOT NULL, label_id INT NOT NULL, PRIMARY KEY(task_id, label_id))');
        $this->addSql('CREATE INDEX IDX_D51000678DB60186 ON task_task_label (task_id)');
        $this->addSql('CREATE INDEX IDX_D510006733B92F39 ON task_task_label (label_id)');
        $this->addSql('CREATE TABLE task_attachment (id INT NOT NULL, message_id INT NOT NULL, uploaded_by_id INT DEFAULT NULL, original_name VARCHAR(255) NOT NULL, mime VARCHAR(127) NOT NULL, size INT NOT NULL, sha256 VARCHAR(64) NOT NULL, path VARCHAR(255) NOT NULL, kind VARCHAR(10) NOT NULL, preview_state VARCHAR(10) DEFAULT \'none\' NOT NULL, pages INT DEFAULT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_task_attachment_preview ON task_attachment (preview_state)');
        $this->addSql('CREATE INDEX IDX_654C9214537A1329 ON task_attachment (message_id)');
        $this->addSql('CREATE INDEX IDX_654C9214A2B28FE8 ON task_attachment (uploaded_by_id)');
        $this->addSql('CREATE TABLE task_label (id INT NOT NULL, name VARCHAR(60) NOT NULL, color VARCHAR(7) DEFAULT \'#64748b\' NOT NULL, sort INT DEFAULT 0 NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C9034BC85E237E06 ON task_label (name)');
        $this->addSql('CREATE TABLE task_member (id INT NOT NULL, task_id INT NOT NULL, user_id INT NOT NULL, via_department_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_task_member ON task_member (task_id, user_id)');
        $this->addSql('CREATE INDEX IDX_4B4D7EAB8DB60186 ON task_member (task_id)');
        $this->addSql('CREATE INDEX IDX_4B4D7EABA76ED395 ON task_member (user_id)');
        $this->addSql('CREATE INDEX IDX_4B4D7EABC5FB59A2 ON task_member (via_department_id)');
        $this->addSql('CREATE TABLE task_message (id INT NOT NULL, task_id INT NOT NULL, author_id INT DEFAULT NULL, kind VARCHAR(10) DEFAULT \'comment\' NOT NULL, text TEXT DEFAULT \'\' NOT NULL, mentions JSON DEFAULT \'[]\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_task_message_task ON task_message (task_id, id)');
        $this->addSql('CREATE INDEX IDX_A95C1A958DB60186 ON task_message (task_id)');
        $this->addSql('CREATE INDEX IDX_A95C1A95F675F31B ON task_message (author_id)');
        $this->addSql('CREATE TABLE task_read (id INT NOT NULL, task_id INT NOT NULL, user_id INT NOT NULL, last_message_id INT DEFAULT 0 NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_task_read ON task_read (task_id, user_id)');
        $this->addSql('CREATE INDEX IDX_EBE4D51C8DB60186 ON task_read (task_id)');
        $this->addSql('CREATE INDEX IDX_EBE4D51CA76ED395 ON task_read (user_id)');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB2561220EA6 FOREIGN KEY (creator_id) REFERENCES telegram_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE task_department ADD CONSTRAINT FK_D10EAA258DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE task_department ADD CONSTRAINT FK_D10EAA25AE80F5DF FOREIGN KEY (department_id) REFERENCES supply_department (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE task_task_label ADD CONSTRAINT FK_D51000678DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE task_task_label ADD CONSTRAINT FK_D510006733B92F39 FOREIGN KEY (label_id) REFERENCES task_label (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE task_attachment ADD CONSTRAINT FK_654C9214537A1329 FOREIGN KEY (message_id) REFERENCES task_message (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE task_attachment ADD CONSTRAINT FK_654C9214A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE task_member ADD CONSTRAINT FK_4B4D7EAB8DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE task_member ADD CONSTRAINT FK_4B4D7EABA76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE task_member ADD CONSTRAINT FK_4B4D7EABC5FB59A2 FOREIGN KEY (via_department_id) REFERENCES supply_department (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE task_message ADD CONSTRAINT FK_A95C1A958DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE task_message ADD CONSTRAINT FK_A95C1A95F675F31B FOREIGN KEY (author_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE task_read ADD CONSTRAINT FK_EBE4D51C8DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE task_read ADD CONSTRAINT FK_EBE4D51CA76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE telegram_user ADD archived_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE telegram_user ADD password VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE telegram_user ADD email VARCHAR(180) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_telegram_user_email ON telegram_user (email)');
        $this->addSql('ALTER TABLE telegram_user ADD position VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE telegram_user ADD work_role VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE telegram_user ADD last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE telegram_user ADD CONSTRAINT FK_F180F05977BE2925 FOREIGN KEY (archived_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_F180F05977BE2925 ON telegram_user (archived_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE task_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE task_attachment_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE task_label_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE task_member_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE task_message_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE task_read_id_seq CASCADE');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB2561220EA6');
        $this->addSql('ALTER TABLE task_department DROP CONSTRAINT FK_D10EAA258DB60186');
        $this->addSql('ALTER TABLE task_department DROP CONSTRAINT FK_D10EAA25AE80F5DF');
        $this->addSql('ALTER TABLE task_task_label DROP CONSTRAINT FK_D51000678DB60186');
        $this->addSql('ALTER TABLE task_task_label DROP CONSTRAINT FK_D510006733B92F39');
        $this->addSql('ALTER TABLE task_attachment DROP CONSTRAINT FK_654C9214537A1329');
        $this->addSql('ALTER TABLE task_attachment DROP CONSTRAINT FK_654C9214A2B28FE8');
        $this->addSql('ALTER TABLE task_member DROP CONSTRAINT FK_4B4D7EAB8DB60186');
        $this->addSql('ALTER TABLE task_member DROP CONSTRAINT FK_4B4D7EABA76ED395');
        $this->addSql('ALTER TABLE task_member DROP CONSTRAINT FK_4B4D7EABC5FB59A2');
        $this->addSql('ALTER TABLE task_message DROP CONSTRAINT FK_A95C1A958DB60186');
        $this->addSql('ALTER TABLE task_message DROP CONSTRAINT FK_A95C1A95F675F31B');
        $this->addSql('ALTER TABLE task_read DROP CONSTRAINT FK_EBE4D51C8DB60186');
        $this->addSql('ALTER TABLE task_read DROP CONSTRAINT FK_EBE4D51CA76ED395');
        $this->addSql('DROP TABLE task');
        $this->addSql('DROP TABLE task_department');
        $this->addSql('DROP TABLE task_task_label');
        $this->addSql('DROP TABLE task_attachment');
        $this->addSql('DROP TABLE task_label');
        $this->addSql('DROP TABLE task_member');
        $this->addSql('DROP TABLE task_message');
        $this->addSql('DROP TABLE task_read');
        $this->addSql('ALTER TABLE telegram_user DROP CONSTRAINT FK_F180F05977BE2925');
        $this->addSql('DROP INDEX IDX_F180F05977BE2925');
        $this->addSql('ALTER TABLE telegram_user DROP archived_by_id');
        $this->addSql('ALTER TABLE telegram_user DROP password');
        $this->addSql('DROP INDEX uniq_telegram_user_email');
        $this->addSql('ALTER TABLE telegram_user DROP email');
        $this->addSql('ALTER TABLE telegram_user DROP position');
        $this->addSql('ALTER TABLE telegram_user DROP work_role');
        $this->addSql('ALTER TABLE telegram_user DROP last_seen_at');
    }
}
