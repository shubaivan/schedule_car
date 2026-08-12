<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Документи заявки: накладні, рахунки, договори, фото. */
final class Version20260812145010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Документи заявки (supply_attachment)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE supply_attachment_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE supply_attachment (id INT NOT NULL, request_id INT NOT NULL, purchase_id INT DEFAULT NULL, uploaded_by_id INT DEFAULT NULL, type VARCHAR(16) NOT NULL, original_name VARCHAR(255) NOT NULL, storage_path VARCHAR(512) NOT NULL, mime VARCHAR(128) NOT NULL, size INT NOT NULL, sha256 VARCHAR(64) NOT NULL, drive_file_id VARCHAR(128) DEFAULT NULL, drive_url VARCHAR(512) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EBF42343279401A ON supply_attachment (storage_path)');
        $this->addSql('CREATE INDEX IDX_EBF42343558FBEB9 ON supply_attachment (purchase_id)');
        $this->addSql('CREATE INDEX IDX_EBF42343A2B28FE8 ON supply_attachment (uploaded_by_id)');
        $this->addSql('CREATE INDEX supply_attachment_request_idx ON supply_attachment (request_id)');
        $this->addSql('ALTER TABLE supply_attachment ADD CONSTRAINT FK_EBF42343427EB8A5 FOREIGN KEY (request_id) REFERENCES supply_request (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE supply_attachment ADD CONSTRAINT FK_EBF42343558FBEB9 FOREIGN KEY (purchase_id) REFERENCES supply_purchase (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE supply_attachment ADD CONSTRAINT FK_EBF42343A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supply_attachment DROP CONSTRAINT FK_EBF42343427EB8A5');
        $this->addSql('ALTER TABLE supply_attachment DROP CONSTRAINT FK_EBF42343558FBEB9');
        $this->addSql('ALTER TABLE supply_attachment DROP CONSTRAINT FK_EBF42343A2B28FE8');
        $this->addSql('DROP TABLE supply_attachment');
        $this->addSql('DROP SEQUENCE supply_attachment_id_seq CASCADE');
    }
}
