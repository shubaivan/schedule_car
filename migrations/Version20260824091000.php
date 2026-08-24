<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Прибрана людина зникає зі списку, але лишається в історії заявок. */
final class Version20260824091000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Архів людей у довіднику';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user ADD archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user DROP archived_at');
    }
}
