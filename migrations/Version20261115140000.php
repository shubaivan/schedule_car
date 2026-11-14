<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «План», потік S5 — Диск і «поділитися»: стан копії на Google Диску в усіх
 * трьох таблицях файлів, тимчасові посилання /s/{token}, загальний стан синхронізації.
 *
 * На живих стендах заявок (PG14) ADD COLUMN з DEFAULT миттєвий; документи, які
 * старе дзеркало вже поклало на Диск (drive_file_id є), одразу позначаються done —
 * сам старий шлях (supply:drive-sync, «Постачання/…») не змінюється.
 */
final class Version20261115140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План S5: drive_state/attempts/error/next_at у task_attachment, supply_attachment, wh_document; share_link; plan_drive_status';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_attachment ADD drive_file_id VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_attachment ADD drive_url VARCHAR(512) DEFAULT NULL');

        foreach (['task_attachment', 'supply_attachment', 'wh_document'] as $table) {
            $this->addSql("ALTER TABLE $table ADD drive_state VARCHAR(8) DEFAULT 'pending' NOT NULL");
            $this->addSql("ALTER TABLE $table ADD drive_attempts INT DEFAULT 0 NOT NULL");
            $this->addSql("ALTER TABLE $table ADD drive_error TEXT DEFAULT NULL");
            $this->addSql("ALTER TABLE $table ADD drive_next_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
            $this->addSql("UPDATE $table SET drive_state = 'done' WHERE drive_file_id IS NOT NULL");
        }

        $this->addSql('CREATE SEQUENCE share_link_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE share_link (id INT NOT NULL, token_hash VARCHAR(64) NOT NULL, file_type VARCHAR(8) NOT NULL, file_id INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, downloads INT DEFAULT 0 NOT NULL, last_download_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_by_id INT NOT NULL, revoked_by_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_share_link_file ON share_link (file_type, file_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_share_link_token ON share_link (token_hash)');
        $this->addSql('CREATE INDEX IDX_8B6B9468B03A8386 ON share_link (created_by_id)');
        $this->addSql('CREATE INDEX IDX_8B6B9468FB8FE773 ON share_link (revoked_by_id)');
        $this->addSql('ALTER TABLE share_link ADD CONSTRAINT FK_8B6B9468B03A8386 FOREIGN KEY (created_by_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE share_link ADD CONSTRAINT FK_8B6B9468FB8FE773 FOREIGN KEY (revoked_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('CREATE TABLE plan_drive_status (id SMALLINT NOT NULL, auth_broken_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, paused_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_ok_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO plan_drive_status (id) VALUES (1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE plan_drive_status');
        $this->addSql('DROP TABLE share_link');
        $this->addSql('DROP SEQUENCE share_link_id_seq');

        foreach (['task_attachment', 'supply_attachment', 'wh_document'] as $table) {
            $this->addSql("ALTER TABLE $table DROP drive_state");
            $this->addSql("ALTER TABLE $table DROP drive_attempts");
            $this->addSql("ALTER TABLE $table DROP drive_error");
            $this->addSql("ALTER TABLE $table DROP drive_next_at");
        }

        $this->addSql('ALTER TABLE task_attachment DROP drive_file_id');
        $this->addSql('ALTER TABLE task_attachment DROP drive_url');
    }
}
