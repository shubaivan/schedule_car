<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Кошик задач: м'яке видалення (deleted_at, хто видалив). 30 днів — відновлення,
 * далі tasks:trash:purge видаляє задачу остаточно. Частковий індекс — лише для кошика
 * й прибирання (живих задач — переважна більшість, їм індекс не потрібен).
 */
final class Version20261120100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: кошик задач (task.deleted_at, task.deleted_by_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD deleted_by_id INT DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN task.deleted_at IS 'Кошик: коли видалено (NULL — жива задача)'");
        $this->addSql("COMMENT ON COLUMN task.deleted_by_id IS 'Кошик: хто видалив'");
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25C76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_527EDB25C76F1F52 ON task (deleted_by_id)');
        $this->addSql('CREATE INDEX idx_task_deleted ON task (deleted_at) WHERE deleted_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_task_deleted');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB25C76F1F52');
        $this->addSql('DROP INDEX IDX_527EDB25C76F1F52');
        $this->addSql('ALTER TABLE task DROP deleted_by_id');
        $this->addSql('ALTER TABLE task DROP deleted_at');
    }
}
