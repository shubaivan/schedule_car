<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Хто зараз у Плані» (ТЗ §24, як portal_presence у PMAP): один рядок на людину —
 * розділ і час останнього heartbeat. Таблиця нова, лише додає; дані тимчасові
 * (рядки старші 3 хвилин прибирає сам heartbeat), у бекап можна не брати.
 */
final class Version20261118093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: хто зараз у Плані (plan_presence)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plan_presence (section VARCHAR(24) NOT NULL, last_seen_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_id INT NOT NULL, PRIMARY KEY (user_id))');
        $this->addSql('CREATE INDEX idx_plan_presence_seen ON plan_presence (last_seen_at)');
        $this->addSql('ALTER TABLE plan_presence ADD CONSTRAINT FK_6712F0DFA76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE plan_presence');
    }
}
