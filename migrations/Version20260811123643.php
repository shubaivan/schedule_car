<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811123643 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Статус доступу користувача: реєстрація за номером + підтвердження менеджером';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user ADD access_decided_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE telegram_user ADD access_status VARCHAR(16) DEFAULT \'pending\' NOT NULL');
        $this->addSql('ALTER TABLE telegram_user ADD access_decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE telegram_user ADD CONSTRAINT FK_F180F0597A65254F FOREIGN KEY (access_decided_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_F180F0597A65254F ON telegram_user (access_decided_by_id)');

        // Ті, хто вже користувався ботом автопарку, не мають опинитись у черзі на підтвердження.
        $this->addSql("UPDATE telegram_user SET access_status = 'approved' WHERE access_status = 'pending'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user DROP CONSTRAINT FK_F180F0597A65254F');
        $this->addSql('DROP INDEX IDX_F180F0597A65254F');
        $this->addSql('ALTER TABLE telegram_user DROP access_decided_by_id');
        $this->addSql('ALTER TABLE telegram_user DROP access_status');
        $this->addSql('ALTER TABLE telegram_user DROP access_decided_at');
    }
}
