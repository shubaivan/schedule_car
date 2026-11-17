<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Імпорт з Planfix: проміжні таблиці (staging) і карта «рядок Planfix → рядок Плану».
 * Лише нові таблиці з префіксом planfix_import_ — наявні не чіпає. Без сутностей ORM
 * (doctrine.dbal.schema_filter їх оминає), пише й читає plan:planfix:import / rollback.
 */
final class Version20261121090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: staging-таблиці імпорту з Planfix (planfix_import_*)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE planfix_import_batch (
            id VARCHAR(40) NOT NULL,
            source TEXT DEFAULT '' NOT NULL,
            options JSON DEFAULT '{}' NOT NULL,
            stats JSON DEFAULT '{}' NOT NULL,
            staged_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            applied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            rolled_back_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY (id))");
        $this->addSql("COMMENT ON TABLE planfix_import_batch IS 'Імпорт Planfix: партія (один запуск над одним вивантаженням)'");

        $this->addSql("CREATE TABLE planfix_import_user (
            batch_id VARCHAR(40) NOT NULL,
            planfix_id INT NOT NULL,
            name VARCHAR(255) DEFAULT '' NOT NULL,
            active BOOLEAN DEFAULT false NOT NULL,
            PRIMARY KEY (batch_id, planfix_id))");

        $this->addSql("CREATE TABLE planfix_import_task (
            batch_id VARCHAR(40) NOT NULL,
            planfix_id INT NOT NULL,
            number INT NOT NULL,
            kind VARCHAR(8) NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT '' NOT NULL,
            status_raw VARCHAR(80) DEFAULT '' NOT NULL,
            status VARCHAR(16) NOT NULL,
            stage VARCHAR(80) DEFAULT NULL,
            urgent BOOLEAN DEFAULT false NOT NULL,
            owner_id INT NOT NULL,
            member_ids JSON DEFAULT '[]' NOT NULL,
            group_names JSON DEFAULT '[]' NOT NULL,
            client VARCHAR(255) DEFAULT NULL,
            project VARCHAR(255) DEFAULT NULL,
            parent_planfix_id INT DEFAULT NULL,
            due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            last_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (batch_id, planfix_id))");

        $this->addSql("CREATE TABLE planfix_import_action (
            batch_id VARCHAR(40) NOT NULL,
            planfix_id BIGINT NOT NULL,
            task_planfix_id INT NOT NULL,
            type VARCHAR(40) NOT NULL,
            at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            owner_id INT NOT NULL,
            decision VARCHAR(10) NOT NULL,
            event_type VARCHAR(16) DEFAULT NULL,
            text TEXT DEFAULT '' NOT NULL,
            hidden BOOLEAN DEFAULT false NOT NULL,
            PRIMARY KEY (batch_id, planfix_id))");
        $this->addSql('CREATE INDEX idx_planfix_import_action_task ON planfix_import_action (batch_id, task_planfix_id, at, planfix_id)');

        $this->addSql('CREATE TABLE planfix_import_map (
            id SERIAL NOT NULL,
            batch_id VARCHAR(40) NOT NULL,
            planfix_type VARCHAR(16) NOT NULL,
            planfix_id BIGINT NOT NULL,
            plan_table VARCHAR(40) NOT NULL,
            plan_id INT NOT NULL,
            PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_planfix_import_map ON planfix_import_map (planfix_type, planfix_id, plan_table, plan_id)');
        $this->addSql('CREATE INDEX idx_planfix_import_map_batch ON planfix_import_map (batch_id, plan_table)');
        $this->addSql("COMMENT ON TABLE planfix_import_map IS 'Імпорт Planfix: кожен створений рядок Плану — за ним rollback видаляє рівно своє'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE planfix_import_map');
        $this->addSql('DROP TABLE planfix_import_action');
        $this->addSql('DROP TABLE planfix_import_task');
        $this->addSql('DROP TABLE planfix_import_user');
        $this->addSql('DROP TABLE planfix_import_batch');
    }
}
