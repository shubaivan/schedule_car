<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240508084428 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX unique_set');
        $this->addSql('ALTER TABLE scheduled_set DROP pavilion');
        $this->addSql('CREATE INDEX unique_set ON scheduled_set (telegram_user_id, year, month, day, hour, car_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX unique_set');
        $this->addSql('ALTER TABLE scheduled_set ADD pavilion INT NOT NULL');
        $this->addSql('CREATE INDEX unique_set ON scheduled_set (telegram_user_id, year, month, day, hour, pavilion)');
    }
}
