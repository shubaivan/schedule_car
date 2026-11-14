<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «План», потік S1 — сповіщення: налаштування (канал × група, тихі години),
 * підписки Web Push, одноразові токени прив'язки Telegram-бота Плану.
 *
 * Лише нові таблиці — на живих стендах заявок міграція миттєва й нічого не
 * переписує. Відомий дифф messenger_messages (Messenger 8) свідомо не чіпаємо.
 */
final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План S1: notification.event_group, notification_pref, notification_setting, push_subscription, telegram_link_token';
    }

    public function up(Schema $schema): void
    {
        // Група сповіщення саме для цієї людини (таблицю notification створив S0, порожня).
        $this->addSql("ALTER TABLE notification ADD event_group VARCHAR(16) DEFAULT '' NOT NULL");

        $this->addSql('CREATE TABLE notification_pref (channel VARCHAR(10) NOT NULL, event_group VARCHAR(16) NOT NULL, enabled BOOLEAN DEFAULT true NOT NULL, user_id INT NOT NULL, PRIMARY KEY (user_id, channel, event_group))');
        $this->addSql('CREATE INDEX IDX_1969D30BA76ED395 ON notification_pref (user_id)');
        $this->addSql('ALTER TABLE notification_pref ADD CONSTRAINT FK_1969D30BA76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql("CREATE TABLE notification_setting (scope VARCHAR(8) DEFAULT 'mine' NOT NULL, telegram BOOLEAN DEFAULT true NOT NULL, push BOOLEAN DEFAULT true NOT NULL, sound BOOLEAN DEFAULT false NOT NULL, quiet_from SMALLINT DEFAULT NULL, quiet_to SMALLINT DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT NOT NULL, PRIMARY KEY (user_id))");
        $this->addSql('ALTER TABLE notification_setting ADD CONSTRAINT FK_8A6A322FA76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE SEQUENCE push_subscription_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE push_subscription (id INT NOT NULL, endpoint VARCHAR(1000) NOT NULL, p256dh VARCHAR(255) NOT NULL, auth VARCHAR(255) NOT NULL, ua VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_ok_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, failed_count INT DEFAULT 0 NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_push_subscription_endpoint ON push_subscription (endpoint)');
        $this->addSql('CREATE INDEX IDX_562830F3A76ED395 ON push_subscription (user_id)');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_562830F3A76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE telegram_link_token (token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id INT NOT NULL, PRIMARY KEY (token_hash))');
        $this->addSql('CREATE INDEX idx_telegram_link_token_user ON telegram_link_token (user_id)');
        $this->addSql('ALTER TABLE telegram_link_token ADD CONSTRAINT FK_A29C4C9FA76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE telegram_link_token');
        $this->addSql('DROP TABLE push_subscription');
        $this->addSql('DROP SEQUENCE push_subscription_id_seq');
        $this->addSql('DROP TABLE notification_setting');
        $this->addSql('DROP TABLE notification_pref');
        $this->addSql('ALTER TABLE notification DROP event_group');
    }
}
