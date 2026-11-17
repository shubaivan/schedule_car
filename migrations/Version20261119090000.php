<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Повний доступ до «Плану» (Олександр, 17.11.2026): прапорець людини; без нього —
 * лише Огляд, Хроніка, Задачі, Пошук, Сповіщення, Люди. Суперадміни — завжди повний.
 * Лише додає колонку (false); кому видати — plan:full-access після деплою.
 */
final class Version20261119090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: telegram_user.plan_full_access';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user ADD plan_full_access BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user DROP plan_full_access');
    }
}
