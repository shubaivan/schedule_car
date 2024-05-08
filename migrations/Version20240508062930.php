<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240508062930 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE car_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE car_driver_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE scheduled_set_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE telegram_user_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE car (id INT NOT NULL, car_number VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE car_driver (id INT NOT NULL, car_id INT DEFAULT NULL, driver_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_90E902BC3C6F69F ON car_driver (car_id)');
        $this->addSql('CREATE INDEX IDX_90E902BC3423909 ON car_driver (driver_id)');
        $this->addSql('CREATE TABLE scheduled_set (id INT NOT NULL, telegram_user_id INT DEFAULT NULL, car_id INT DEFAULT NULL, year INT NOT NULL, month INT NOT NULL, day INT NOT NULL, hour INT NOT NULL, pavilion INT NOT NULL, scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_9DC695EAFC28B263 ON scheduled_set (telegram_user_id)');
        $this->addSql('CREATE INDEX IDX_9DC695EAC3C6F69F ON scheduled_set (car_id)');
        $this->addSql('CREATE INDEX unique_set ON scheduled_set (telegram_user_id, year, month, day, hour, pavilion)');
        $this->addSql('CREATE TABLE telegram_user (id INT NOT NULL, chat_id VARCHAR(255) DEFAULT NULL, telegram_id VARCHAR(255) NOT NULL, phone_number VARCHAR(255) DEFAULT NULL, first_name VARCHAR(255) DEFAULT NULL, last_name VARCHAR(255) DEFAULT NULL, username VARCHAR(255) DEFAULT NULL, language_code VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F180F059CC0B3066 ON telegram_user (telegram_id)');
        $this->addSql('CREATE TABLE messenger_messages (id BIGSERIAL NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
        $this->addSql('COMMENT ON COLUMN messenger_messages.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN messenger_messages.available_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN messenger_messages.delivered_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE OR REPLACE FUNCTION notify_messenger_messages() RETURNS TRIGGER AS $$
            BEGIN
                PERFORM pg_notify(\'messenger_messages\', NEW.queue_name::text);
                RETURN NEW;
            END;
        $$ LANGUAGE plpgsql;');
        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages;');
        $this->addSql('CREATE TRIGGER notify_trigger AFTER INSERT OR UPDATE ON messenger_messages FOR EACH ROW EXECUTE PROCEDURE notify_messenger_messages();');
        $this->addSql('ALTER TABLE car_driver ADD CONSTRAINT FK_90E902BC3C6F69F FOREIGN KEY (car_id) REFERENCES car (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE car_driver ADD CONSTRAINT FK_90E902BC3423909 FOREIGN KEY (driver_id) REFERENCES telegram_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE scheduled_set ADD CONSTRAINT FK_9DC695EAFC28B263 FOREIGN KEY (telegram_user_id) REFERENCES telegram_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE scheduled_set ADD CONSTRAINT FK_9DC695EAC3C6F69F FOREIGN KEY (car_id) REFERENCES car (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE car_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE car_driver_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE scheduled_set_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE telegram_user_id_seq CASCADE');
        $this->addSql('ALTER TABLE car_driver DROP CONSTRAINT FK_90E902BC3C6F69F');
        $this->addSql('ALTER TABLE car_driver DROP CONSTRAINT FK_90E902BC3423909');
        $this->addSql('ALTER TABLE scheduled_set DROP CONSTRAINT FK_9DC695EAFC28B263');
        $this->addSql('ALTER TABLE scheduled_set DROP CONSTRAINT FK_9DC695EAC3C6F69F');
        $this->addSql('DROP TABLE car');
        $this->addSql('DROP TABLE car_driver');
        $this->addSql('DROP TABLE scheduled_set');
        $this->addSql('DROP TABLE telegram_user');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
