<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Вхід через Telegram: одноразові спроби входу (plan_login_nonce, у базі лише sha256
 * nonce) і заявки на приєднання від людей, чийого номера ще немає в Плані
 * (plan_join_request). Обидві таблиці нові — міграція лише додає.
 */
final class Version20261116150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: вхід через Telegram (plan_login_nonce) і заявки на приєднання (plan_join_request)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE plan_join_request_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE plan_login_nonce_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE plan_join_request (id INT NOT NULL, telegram_id VARCHAR(32) NOT NULL, chat_id VARCHAR(32) DEFAULT NULL, first_name VARCHAR(255) DEFAULT NULL, last_name VARCHAR(255) DEFAULT NULL, username VARCHAR(255) DEFAULT NULL, phone VARCHAR(32) NOT NULL, status VARCHAR(16) DEFAULT \'pending\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, decided_by_id INT DEFAULT NULL, user_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_plan_join_request_status ON plan_join_request (status, created_at)');
        $this->addSql('CREATE INDEX idx_plan_join_request_tg ON plan_join_request (telegram_id, created_at)');
        $this->addSql('CREATE INDEX IDX_33A6E5F7E26B496B ON plan_join_request (decided_by_id)');
        $this->addSql('CREATE INDEX IDX_33A6E5F7A76ED395 ON plan_join_request (user_id)');
        $this->addSql('CREATE TABLE plan_login_nonce (id INT NOT NULL, nonce_hash VARCHAR(64) NOT NULL, code VARCHAR(8) NOT NULL, status VARCHAR(16) DEFAULT \'pending\' NOT NULL, telegram_id VARCHAR(32) DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_40ED8D02C2C78D66 ON plan_login_nonce (nonce_hash)');
        $this->addSql('CREATE INDEX idx_plan_login_nonce_tg ON plan_login_nonce (telegram_id, status)');
        $this->addSql('CREATE INDEX idx_plan_login_nonce_expires ON plan_login_nonce (expires_at)');
        $this->addSql('CREATE INDEX IDX_40ED8D02A76ED395 ON plan_login_nonce (user_id)');
        $this->addSql('ALTER TABLE plan_join_request ADD CONSTRAINT FK_33A6E5F7E26B496B FOREIGN KEY (decided_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plan_join_request ADD CONSTRAINT FK_33A6E5F7A76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plan_login_nonce ADD CONSTRAINT FK_40ED8D02A76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE plan_join_request_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE plan_login_nonce_id_seq CASCADE');
        $this->addSql('ALTER TABLE plan_join_request DROP CONSTRAINT FK_33A6E5F7E26B496B');
        $this->addSql('ALTER TABLE plan_join_request DROP CONSTRAINT FK_33A6E5F7A76ED395');
        $this->addSql('ALTER TABLE plan_login_nonce DROP CONSTRAINT FK_40ED8D02A76ED395');
        $this->addSql('DROP TABLE plan_join_request');
        $this->addSql('DROP TABLE plan_login_nonce');
    }
}
