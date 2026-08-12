<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Довідник постачальників: у кого саме купили за заявкою. */
final class Version20260812130714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Довідник постачальників (supply_supplier)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE supply_supplier_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE supply_supplier (id INT NOT NULL, created_by_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, name_normalized VARCHAR(255) NOT NULL, edrpou VARCHAR(16) DEFAULT NULL, phone VARCHAR(32) DEFAULT NULL, contact_person VARCHAR(255) DEFAULT NULL, note TEXT DEFAULT NULL, active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        // Назва без регістру й розділових знаків: саме вона ловить повтори.
        $this->addSql('CREATE UNIQUE INDEX UNIQ_33F10477E1B35095 ON supply_supplier (name_normalized)');
        // NULL-и в Postgres не конфліктують, тож постачальники без коду співіснують.
        $this->addSql('CREATE UNIQUE INDEX supply_supplier_edrpou_idx ON supply_supplier (edrpou)');
        $this->addSql('CREATE INDEX IDX_33F10477B03A8386 ON supply_supplier (created_by_id)');
        $this->addSql('ALTER TABLE supply_supplier ADD CONSTRAINT FK_33F10477B03A8386 FOREIGN KEY (created_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supply_supplier DROP CONSTRAINT FK_33F10477B03A8386');
        $this->addSql('DROP TABLE supply_supplier');
        $this->addSql('DROP SEQUENCE supply_supplier_id_seq CASCADE');
    }
}
