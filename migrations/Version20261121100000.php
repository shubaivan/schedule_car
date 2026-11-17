<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Імпорт з Planfix, правки ревʼю: надгробок у карті (задачу видалили руками — повторний
 * імпорт її не повертає), роль людини Planfix (діючий / колишній / контакт з сайту),
 * час закриття задачі за останньою зміною статусу. Лише нові колонки в planfix_import_*.
 */
final class Version20261121100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: planfix_import_map.deleted_at, planfix_import_user.role, planfix_import_task.closed_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planfix_import_map ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN planfix_import_map.deleted_at IS 'Рядок Плану видалили поза імпортом — повторний імпорт його не відтворює'");
        $this->addSql("ALTER TABLE planfix_import_user ADD role VARCHAR(10) DEFAULT 'former' NOT NULL");
        $this->addSql("COMMENT ON COLUMN planfix_import_user.role IS 'active | former | contact — підпис заглушки'");
        $this->addSql('ALTER TABLE planfix_import_task ADD closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN planfix_import_task.closed_at IS 'Остання зміна статусу в Planfix (для закритих)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planfix_import_task DROP closed_at');
        $this->addSql('ALTER TABLE planfix_import_user DROP role');
        $this->addSql('ALTER TABLE planfix_import_map DROP deleted_at');
    }
}
