<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Вхід на 30 днів (remember-me): «покоління» входів людини. +1 («Це був не я» в боті) —
 * завершуються всі сесії й запам'ятовані входи на всіх пристроях. Лише додає колонку.
 */
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: telegram_user.session_epoch (вхід на 30 днів)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user ADD session_epoch INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user DROP session_epoch');
    }
}
