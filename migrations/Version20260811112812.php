<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811112812 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Модуль постачання: заявки, коментарі, лог статусів, підрозділи; роль/підрозділ у telegram_user; одноразові токени входу в CRM';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE login_token_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE supply_comment_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE supply_department_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE supply_request_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE supply_status_log_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE login_token (id INT NOT NULL, user_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_594766AFB3BC57DA ON login_token (token_hash)');
        $this->addSql('CREATE INDEX IDX_594766AFA76ED395 ON login_token (user_id)');
        $this->addSql('CREATE TABLE supply_comment (id INT NOT NULL, request_id INT NOT NULL, author_id INT DEFAULT NULL, text TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_28C30EC1F675F31B ON supply_comment (author_id)');
        $this->addSql('CREATE INDEX supply_comment_request_idx ON supply_comment (request_id)');
        $this->addSql('CREATE TABLE supply_department (id INT NOT NULL, name VARCHAR(255) NOT NULL, active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5FB61B725E237E06 ON supply_department (name)');
        $this->addSql('CREATE TABLE supply_request (id INT NOT NULL, author_id INT NOT NULL, department_id INT DEFAULT NULL, number VARCHAR(16) NOT NULL, item VARCHAR(255) NOT NULL, quantity NUMERIC(12, 3) NOT NULL, unit VARCHAR(16) NOT NULL, site VARCHAR(255) DEFAULT NULL, need_by DATE DEFAULT NULL, urgent BOOLEAN DEFAULT false NOT NULL, note TEXT DEFAULT NULL, status VARCHAR(32) NOT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8720D33296901F54 ON supply_request (number)');
        $this->addSql('CREATE INDEX IDX_8720D332AE80F5DF ON supply_request (department_id)');
        $this->addSql('CREATE INDEX supply_request_status_idx ON supply_request (status)');
        $this->addSql('CREATE INDEX supply_request_author_idx ON supply_request (author_id)');
        $this->addSql('CREATE TABLE supply_status_log (id INT NOT NULL, request_id INT NOT NULL, author_id INT DEFAULT NULL, status_from VARCHAR(32) DEFAULT NULL, status_to VARCHAR(32) NOT NULL, comment TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C3FBC29FF675F31B ON supply_status_log (author_id)');
        $this->addSql('CREATE INDEX supply_status_log_request_idx ON supply_status_log (request_id)');
        $this->addSql('ALTER TABLE login_token ADD CONSTRAINT FK_594766AFA76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE supply_comment ADD CONSTRAINT FK_28C30EC1427EB8A5 FOREIGN KEY (request_id) REFERENCES supply_request (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE supply_comment ADD CONSTRAINT FK_28C30EC1F675F31B FOREIGN KEY (author_id) REFERENCES telegram_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE supply_request ADD CONSTRAINT FK_8720D332F675F31B FOREIGN KEY (author_id) REFERENCES telegram_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE supply_request ADD CONSTRAINT FK_8720D332AE80F5DF FOREIGN KEY (department_id) REFERENCES supply_department (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE supply_status_log ADD CONSTRAINT FK_C3FBC29F427EB8A5 FOREIGN KEY (request_id) REFERENCES supply_request (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE supply_status_log ADD CONSTRAINT FK_C3FBC29FF675F31B FOREIGN KEY (author_id) REFERENCES telegram_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE telegram_user ADD department_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE telegram_user ADD supply_role VARCHAR(16) DEFAULT \'worker\' NOT NULL');
        $this->addSql('ALTER TABLE telegram_user ADD CONSTRAINT FK_F180F059AE80F5DF FOREIGN KEY (department_id) REFERENCES supply_department (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_F180F059AE80F5DF ON telegram_user (department_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user DROP CONSTRAINT FK_F180F059AE80F5DF');
        $this->addSql('DROP SEQUENCE login_token_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE supply_comment_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE supply_department_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE supply_request_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE supply_status_log_id_seq CASCADE');
        $this->addSql('ALTER TABLE login_token DROP CONSTRAINT FK_594766AFA76ED395');
        $this->addSql('ALTER TABLE supply_comment DROP CONSTRAINT FK_28C30EC1427EB8A5');
        $this->addSql('ALTER TABLE supply_comment DROP CONSTRAINT FK_28C30EC1F675F31B');
        $this->addSql('ALTER TABLE supply_request DROP CONSTRAINT FK_8720D332F675F31B');
        $this->addSql('ALTER TABLE supply_request DROP CONSTRAINT FK_8720D332AE80F5DF');
        $this->addSql('ALTER TABLE supply_status_log DROP CONSTRAINT FK_C3FBC29F427EB8A5');
        $this->addSql('ALTER TABLE supply_status_log DROP CONSTRAINT FK_C3FBC29FF675F31B');
        $this->addSql('DROP TABLE login_token');
        $this->addSql('DROP TABLE supply_comment');
        $this->addSql('DROP TABLE supply_department');
        $this->addSql('DROP TABLE supply_request');
        $this->addSql('DROP TABLE supply_status_log');
        $this->addSql('DROP INDEX IDX_F180F059AE80F5DF');
        $this->addSql('ALTER TABLE telegram_user DROP department_id');
        $this->addSql('ALTER TABLE telegram_user DROP supply_role');
    }
}
