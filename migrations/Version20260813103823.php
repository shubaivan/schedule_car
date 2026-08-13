<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Довідник телефонів: номер + роль, яку людина отримає при реєстрації в боті.
 */
final class Version20260813103823 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Довідник телефонів з ролями (supply_staff_phone)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE supply_staff_phone_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE supply_staff_phone (id INT NOT NULL, created_by_id INT DEFAULT NULL, applied_to_id INT DEFAULT NULL, phone VARCHAR(32) NOT NULL, tail VARCHAR(16) NOT NULL, name VARCHAR(255) DEFAULT NULL, role VARCHAR(16) NOT NULL, note TEXT DEFAULT NULL, applied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_8A5EEECEB03A8386 ON supply_staff_phone (created_by_id)');
        $this->addSql('CREATE INDEX IDX_8A5EEECED15F842C ON supply_staff_phone (applied_to_id)');
        $this->addSql('CREATE UNIQUE INDEX supply_staff_phone_tail_idx ON supply_staff_phone (tail)');
        $this->addSql('ALTER TABLE supply_staff_phone ADD CONSTRAINT FK_8A5EEECEB03A8386 FOREIGN KEY (created_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE supply_staff_phone ADD CONSTRAINT FK_8A5EEECED15F842C FOREIGN KEY (applied_to_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE supply_staff_phone_id_seq CASCADE');
        $this->addSql('ALTER TABLE supply_staff_phone DROP CONSTRAINT FK_8A5EEECEB03A8386');
        $this->addSql('ALTER TABLE supply_staff_phone DROP CONSTRAINT FK_8A5EEECED15F842C');
        $this->addSql('DROP TABLE supply_staff_phone');
    }
}
