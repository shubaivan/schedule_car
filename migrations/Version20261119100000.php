<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Пароль для входу в «План» (17.11.2026): одноразові посилання «встановити пароль»,
 * які бот надсилає в Telegram людини (PasswordSetup). Лише sha256 токена; нова таблиця.
 */
final class Version20261119100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: plan_password_token (пароль за посиланням із бота)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE plan_password_token_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE plan_password_token (id INT NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1750C2EB3BC57DA ON plan_password_token (token_hash)');
        $this->addSql('CREATE INDEX idx_plan_password_token_user ON plan_password_token (user_id)');
        // Вхід за паролем на «Плані» — лише з паролем, який людина задала сама (старі не діють).
        $this->addSql('ALTER TABLE telegram_user ADD password_set_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE plan_password_token ADD CONSTRAINT FK_1750C2EA76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE plan_password_token');
        $this->addSql('ALTER TABLE telegram_user DROP password_set_at');
        $this->addSql('DROP SEQUENCE plan_password_token_id_seq');
    }
}
