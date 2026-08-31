<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Нагадування про прострочення — один раз на строк, а не щоранку. */
final class Version20260831140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Мітка відправленого нагадування по простроченій заявці';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supply_request ADD overdue_notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supply_request DROP overdue_notified_at');
    }
}
