<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260818132036 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE driver_phone_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE driver_phone (id INT NOT NULL, car_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, applied_to_id INT DEFAULT NULL, phone VARCHAR(32) NOT NULL, tail VARCHAR(16) NOT NULL, name VARCHAR(255) DEFAULT NULL, note TEXT DEFAULT NULL, applied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C4CD5AF0C3C6F69F ON driver_phone (car_id)');
        $this->addSql('CREATE INDEX IDX_C4CD5AF0B03A8386 ON driver_phone (created_by_id)');
        $this->addSql('CREATE INDEX IDX_C4CD5AF0D15F842C ON driver_phone (applied_to_id)');
        $this->addSql('CREATE UNIQUE INDEX driver_phone_tail_idx ON driver_phone (tail)');
        $this->addSql('ALTER TABLE driver_phone ADD CONSTRAINT FK_C4CD5AF0C3C6F69F FOREIGN KEY (car_id) REFERENCES car (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE driver_phone ADD CONSTRAINT FK_C4CD5AF0B03A8386 FOREIGN KEY (created_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE driver_phone ADD CONSTRAINT FK_C4CD5AF0D15F842C FOREIGN KEY (applied_to_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE car ADD model VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE car ADD active BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE driver_phone_id_seq CASCADE');
        $this->addSql('ALTER TABLE driver_phone DROP CONSTRAINT FK_C4CD5AF0C3C6F69F');
        $this->addSql('ALTER TABLE driver_phone DROP CONSTRAINT FK_C4CD5AF0B03A8386');
        $this->addSql('ALTER TABLE driver_phone DROP CONSTRAINT FK_C4CD5AF0D15F842C');
        $this->addSql('DROP TABLE driver_phone');
        $this->addSql('ALTER TABLE car DROP model');
        $this->addSql('ALTER TABLE car DROP active');
    }
}
