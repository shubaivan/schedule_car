<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
/** Журнал входів: хто, коли, звідки й чи вдало. */
final class Version20260928094940 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Журнал входів у CRM і склад';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE crm_login_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE crm_login (id INT NOT NULL, user_id INT DEFAULT NULL, name VARCHAR(255) DEFAULT NULL, success BOOLEAN NOT NULL, reason VARCHAR(255) DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, target VARCHAR(255) DEFAULT NULL, at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_16019428A76ED395 ON crm_login (user_id)');
        $this->addSql('CREATE INDEX crm_login_at_idx ON crm_login (at)');
        $this->addSql('ALTER TABLE crm_login ADD CONSTRAINT FK_16019428A76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE crm_login_id_seq CASCADE');
        $this->addSql('ALTER TABLE crm_login DROP CONSTRAINT FK_16019428A76ED395');
        $this->addSql('DROP TABLE crm_login');
    }
}
