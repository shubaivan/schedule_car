<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** «Куди їде» — окрема колонка броні: у розкладі маршрут читають першим. */
final class Version20260819121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Маршрут поїздки окремим полем';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_set ADD destination TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_set DROP destination');
    }
}
