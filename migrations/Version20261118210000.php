<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «План», етап 1: довідник контрагентів (counterparty + counterparty_contact).
 * Задача отримує необовʼязкове посилання на картку (task.client лишається рядком-запасом),
 * постачальник заявок і клієнт складу — необовʼязкове посилання на ту саму юрособу
 * (заповнює plan:counterparties:link). Лише нові таблиці й nullable-колонки —
 * стенди заявок нічого не помічають.
 */
final class Version20261118210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: контрагенти, контактні особи, звʼязок із задачами, постачальниками й клієнтами складу';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE counterparty_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE counterparty_contact_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE counterparty (id INT NOT NULL, kind VARCHAR(8) DEFAULT \'client\' NOT NULL, legal_form VARCHAR(8) DEFAULT \'company\' NOT NULL, name VARCHAR(255) NOT NULL, short_name VARCHAR(120) DEFAULT NULL, name_normalized VARCHAR(255) NOT NULL, code VARCHAR(10) DEFAULT NULL, vat_status VARCHAR(12) DEFAULT NULL, vat_number VARCHAR(12) DEFAULT NULL, legal_address TEXT DEFAULT NULL, actual_address TEXT DEFAULT NULL, phone VARCHAR(32) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, iban VARCHAR(34) DEFAULT NULL, bank_name VARCHAR(255) DEFAULT NULL, payment_terms VARCHAR(16) DEFAULT NULL, deferral_days SMALLINT DEFAULT NULL, credit_limit NUMERIC(14, 2) DEFAULT NULL, note TEXT DEFAULT NULL, external_code VARCHAR(64) DEFAULT NULL, archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, manager_id INT DEFAULT NULL, archived_by_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_counterparty_name_normalized ON counterparty (name_normalized)');
        $this->addSql('CREATE INDEX idx_counterparty_manager ON counterparty (manager_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_counterparty_code ON counterparty (code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_counterparty_external_code ON counterparty (external_code)');
        $this->addSql('CREATE INDEX IDX_9B3DE79C77BE2925 ON counterparty (archived_by_id)');
        $this->addSql('CREATE INDEX IDX_9B3DE79CB03A8386 ON counterparty (created_by_id)');
        $this->addSql('CREATE TABLE counterparty_contact (id INT NOT NULL, name VARCHAR(255) NOT NULL, role VARCHAR(120) DEFAULT NULL, phone VARCHAR(32) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, is_primary BOOLEAN DEFAULT false NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, counterparty_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_counterparty_contact_owner ON counterparty_contact (counterparty_id)');
        $this->addSql('CREATE INDEX idx_counterparty_contact_phone ON counterparty_contact (phone)');
        $this->addSql('ALTER TABLE counterparty ADD CONSTRAINT FK_9B3DE79C783E3463 FOREIGN KEY (manager_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE counterparty ADD CONSTRAINT FK_9B3DE79C77BE2925 FOREIGN KEY (archived_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE counterparty ADD CONSTRAINT FK_9B3DE79CB03A8386 FOREIGN KEY (created_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE counterparty_contact ADD CONSTRAINT FK_98303C10DB1FAD05 FOREIGN KEY (counterparty_id) REFERENCES counterparty (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE supply_supplier ADD counterparty_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE supply_supplier ADD CONSTRAINT FK_33F10477DB1FAD05 FOREIGN KEY (counterparty_id) REFERENCES counterparty (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_33F10477DB1FAD05 ON supply_supplier (counterparty_id)');
        $this->addSql('ALTER TABLE task ADD counterparty_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25DB1FAD05 FOREIGN KEY (counterparty_id) REFERENCES counterparty (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_527EDB25DB1FAD05 ON task (counterparty_id)');
        $this->addSql('ALTER TABLE wh_client ADD counterparty_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE wh_client ADD CONSTRAINT FK_D2DE3D14DB1FAD05 FOREIGN KEY (counterparty_id) REFERENCES counterparty (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_D2DE3D14DB1FAD05 ON wh_client (counterparty_id)');
    }

    public function down(Schema $schema): void
    {
        // Спершу посилання на контрагентів і їхні сліди в журналі (старий код не знає типу
        // суб'єкта «counterparty» і впав би на сторінці аудиту), потім самі таблиці.
        $this->addSql("DELETE FROM plan_event WHERE subject_type = 'counterparty'");
        $this->addSql("DELETE FROM plan_read WHERE subject_type = 'counterparty'");
        $this->addSql("DELETE FROM search_document WHERE subject_type = 'counterparty'");
        $this->addSql('ALTER TABLE supply_supplier DROP counterparty_id');
        $this->addSql('ALTER TABLE task DROP counterparty_id');
        $this->addSql('ALTER TABLE wh_client DROP counterparty_id');
        $this->addSql('DROP TABLE counterparty_contact');
        $this->addSql('DROP TABLE counterparty');
        $this->addSql('DROP SEQUENCE counterparty_id_seq');
        $this->addSql('DROP SEQUENCE counterparty_contact_id_seq');
    }
}
