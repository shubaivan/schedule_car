<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «План», потік S0 — ядро подій: журнал plan_event (він же аудит), відмітки
 * споживачів (plan_event_consumed), остання
 * подія суб'єкта для Хроніки, центр сповіщень, прапорець суперадміна.
 *
 * Лише нові таблиці й одна колонка з DEFAULT — на живих стендах заявок (PG14)
 * міграція миттєва й нічого не переписує. Відомий дифф messenger_messages
 * (Messenger 8) свідомо не чіпаємо — він не стосується цього потоку.
 */
final class Version20261115080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План S0: plan_event, plan_event_consumed, plan_subject_activity, notification, telegram_user.is_superadmin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE plan_event_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE plan_event (id BIGINT NOT NULL, type VARCHAR(40) NOT NULL, subject_type VARCHAR(16) NOT NULL, subject_id INT NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, payload JSONB NOT NULL, ip VARCHAR(45) DEFAULT NULL, channel VARCHAR(8) NOT NULL, actor_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_plan_event_subject ON plan_event (subject_type, subject_id, id)');
        $this->addSql('CREATE INDEX idx_plan_event_occurred ON plan_event (occurred_at)');
        $this->addSql('CREATE INDEX idx_plan_event_actor ON plan_event (actor_id, id)');
        $this->addSql('CREATE INDEX idx_plan_event_type ON plan_event (type, id)');
        $this->addSql('CREATE INDEX IDX_68084F5B10DAF24A ON plan_event (actor_id)');
        $this->addSql('ALTER TABLE plan_event ADD CONSTRAINT FK_68084F5B10DAF24A FOREIGN KEY (actor_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE');

        // Хто з споживачів яку подію вже взяв — повторна доставка черги не дублює Telegram.
        $this->addSql('CREATE TABLE plan_event_consumed (consumer VARCHAR(120) NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, event_id BIGINT NOT NULL, PRIMARY KEY (event_id, consumer))');
        $this->addSql('CREATE INDEX IDX_7DBD0BAC71F7E88B ON plan_event_consumed (event_id)');
        $this->addSql('ALTER TABLE plan_event_consumed ADD CONSTRAINT FK_7DBD0BAC71F7E88B FOREIGN KEY (event_id) REFERENCES plan_event (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE plan_subject_activity (subject_type VARCHAR(16) NOT NULL, subject_id INT NOT NULL, last_event_id BIGINT NOT NULL, last_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (subject_type, subject_id))');
        $this->addSql('CREATE INDEX idx_plan_subject_activity_last ON plan_subject_activity (last_at, last_event_id)');

        $this->addSql('CREATE SEQUENCE notification_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE notification (id BIGINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, delivered_tg_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, delivered_push_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id INT NOT NULL, event_id BIGINT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_notification_inbox ON notification (user_id, read_at, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_user_event ON notification (user_id, event_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CAA76ED395 ON notification (user_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CA71F7E88B ON notification (event_id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA71F7E88B FOREIGN KEY (event_id) REFERENCES plan_event (id) ON DELETE CASCADE NOT DEFERRABLE');

        // Миттєво на PG11+ (лише метадані), але бере короткий ACCESS EXCLUSIVE — не чекаємо довго за чужими запитами.
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql('ALTER TABLE telegram_user ADD is_superadmin BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user DROP is_superadmin');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP SEQUENCE notification_id_seq');
        $this->addSql('DROP TABLE plan_subject_activity');
        $this->addSql('DROP TABLE plan_event_consumed');
        $this->addSql('DROP TABLE plan_event');
        $this->addSql('DROP SEQUENCE plan_event_id_seq');
    }
}
