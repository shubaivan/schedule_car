<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Імпорт з Planfix: файли, забрані через API (manifest.jsonl), — staging партії.
 * Кожен файл привʼязаний до задачі й до дії (коментаря), у якій його додали;
 * action_planfix_id = NULL — файл самої задачі (ляже в повідомлення «задачу створено»).
 */
final class Version20261121110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: planfix_import_file — staging файлів Planfix';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE planfix_import_file (
            batch_id VARCHAR(40) NOT NULL,
            planfix_id BIGINT NOT NULL,
            task_planfix_id INT NOT NULL,
            action_planfix_id BIGINT DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            mime VARCHAR(127) NOT NULL,
            kind VARCHAR(10) NOT NULL,
            size BIGINT NOT NULL,
            sha256 VARCHAR(64) NOT NULL,
            local_path TEXT NOT NULL,
            PRIMARY KEY (batch_id, planfix_id))");
        $this->addSql('CREATE INDEX idx_planfix_import_file_task ON planfix_import_file (batch_id, task_planfix_id)');
        $this->addSql("COMMENT ON TABLE planfix_import_file IS 'Імпорт Planfix: файл задачі чи коментаря (з API), чекає --apply'");
        $this->addSql("COMMENT ON COLUMN planfix_import_file.action_planfix_id IS 'Дія Planfix, де додано файл; NULL — файл самої задачі'");
        $this->addSql("COMMENT ON COLUMN planfix_import_file.local_path IS 'Де лежить завантажений файл (тека staging на сервері)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE planfix_import_file');
    }
}
