<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Мітка менеджера для керівника: червона, жовта, зелена. */
final class Version20260824090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Кольорова мітка заявки';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE supply_request ADD accent VARCHAR(16) DEFAULT 'none' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supply_request DROP accent');
    }
}
