<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Факт закупівлі за заявкою: у кого, скільки, почім. */
final class Version20260812140135 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Закупівлі за заявками (supply_purchase)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE supply_purchase_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE supply_purchase (id INT NOT NULL, request_id INT NOT NULL, supplier_id INT NOT NULL, created_by_id INT DEFAULT NULL, quantity NUMERIC(12, 3) DEFAULT NULL, price_per_unit NUMERIC(14, 2) DEFAULT NULL, total_amount NUMERIC(14, 2) NOT NULL, currency VARCHAR(3) DEFAULT \'UAH\' NOT NULL, vat_included BOOLEAN DEFAULT true NOT NULL, invoice_number VARCHAR(64) DEFAULT NULL, purchased_at DATE DEFAULT NULL, payment VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C9CCB932B03A8386 ON supply_purchase (created_by_id)');
        $this->addSql('CREATE INDEX supply_purchase_request_idx ON supply_purchase (request_id)');
        $this->addSql('CREATE INDEX supply_purchase_supplier_idx ON supply_purchase (supplier_id)');
        $this->addSql('ALTER TABLE supply_purchase ADD CONSTRAINT FK_C9CCB932427EB8A5 FOREIGN KEY (request_id) REFERENCES supply_request (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE supply_purchase ADD CONSTRAINT FK_C9CCB9322ADD6D8C FOREIGN KEY (supplier_id) REFERENCES supply_supplier (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE supply_purchase ADD CONSTRAINT FK_C9CCB932B03A8386 FOREIGN KEY (created_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supply_purchase DROP CONSTRAINT FK_C9CCB932427EB8A5');
        $this->addSql('ALTER TABLE supply_purchase DROP CONSTRAINT FK_C9CCB9322ADD6D8C');
        $this->addSql('ALTER TABLE supply_purchase DROP CONSTRAINT FK_C9CCB932B03A8386');
        $this->addSql('DROP TABLE supply_purchase');
        $this->addSql('DROP SEQUENCE supply_purchase_id_seq CASCADE');
    }
}
