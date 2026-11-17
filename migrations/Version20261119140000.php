<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ролі й доступ (17.11): зміни прав ролей адміністратором (plan_role_permission; рядка немає —
 * діє значення з коду) і нова роль «Системний адміністратор» (work_role = 'sysadmin' —
 * колонка varchar(16) уже вміщує, змін схеми telegram_user не треба).
 */
final class Version20261119140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: plan_role_permission (права ролей, сторінка «Ролі й доступ»)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plan_role_permission (role VARCHAR(16) NOT NULL, permission VARCHAR(32) NOT NULL, allowed BOOLEAN NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_by_id INT DEFAULT NULL, PRIMARY KEY (role, permission))');
        $this->addSql('CREATE INDEX IDX_8F17CFF8896DBBDE ON plan_role_permission (updated_by_id)');
        $this->addSql('ALTER TABLE plan_role_permission ADD CONSTRAINT FK_PLAN_ROLE_PERM_BY FOREIGN KEY (updated_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE plan_role_permission');
    }
}
