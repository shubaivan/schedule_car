<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «План», потік S4 — пошук із фасетами й Хроніка v2:
 * - search_document — одна денормалізована таблиця пошуку (веде SearchProjector,
 *   бекфіл — `plan:search:reindex`);
 * - plan_read — до якої події людина дочитала заявку / позицію складу (задачі
 *   й далі через task_read);
 * - plan_saved_search — збережені пошуки.
 *
 * Лише нові таблиці (PG14-сумісно: без NULLS NOT DISTINCT, генерованих колонок
 * і MERGE). pg_trgm уже ввімкнено міграцією Version20261114180000. Після migrate:
 * `plan:search:reindex` і `plan:chronicle:rebuild`, потім ANALYZE search_document.
 */
final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План S4: search_document, plan_read, plan_saved_search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE search_document_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql("CREATE TABLE search_document (
            id BIGINT NOT NULL,
            doc_type VARCHAR(16) NOT NULL,
            subject_type VARCHAR(16) NOT NULL,
            subject_id INT NOT NULL,
            ref_id INT NOT NULL,
            title TEXT DEFAULT '' NOT NULL,
            body TEXT DEFAULT '' NOT NULL,
            client TEXT DEFAULT '' NOT NULL,
            search_text TEXT DEFAULT '' NOT NULL,
            author_id INT DEFAULT NULL,
            manager_id INT DEFAULT NULL,
            executor_ids JSONB DEFAULT '[]' NOT NULL,
            department_ids JSONB DEFAULT '[]' NOT NULL,
            status VARCHAR(40) DEFAULT NULL,
            visible_all BOOLEAN DEFAULT false NOT NULL,
            visible_to JSONB DEFAULT '[]' NOT NULL,
            capability VARCHAR(24) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_search_document_ref ON search_document (doc_type, ref_id)');
        $this->addSql('CREATE INDEX idx_search_document_subject ON search_document (subject_type, subject_id)');
        $this->addSql('CREATE INDEX idx_search_document_updated ON search_document (updated_at, id)');
        $this->addSql('CREATE INDEX idx_search_document_created ON search_document (created_at)');
        $this->addSql('CREATE INDEX idx_search_document_author ON search_document (author_id)');
        $this->addSql('CREATE INDEX idx_search_document_manager ON search_document (manager_id)');
        $this->addSql('CREATE INDEX idx_search_document_status ON search_document (status)');
        // Збіг «за частиною слова» (LIKE '%w%', слова від 3 літер) і «слово починається з» для
        // 1–2 літер (tsvector 'simple' з префіксом — триграм для них не працює).
        $this->addSql('CREATE INDEX idx_search_document_trgm ON search_document USING gin (search_text gin_trgm_ops)');
        $this->addSql("CREATE INDEX idx_search_document_fts ON search_document USING gin (to_tsvector('simple', search_text))");
        $this->addSql('CREATE INDEX idx_search_document_client_trgm ON search_document USING gin (lower(client) gin_trgm_ops)');
        // Фасети-масиви: executor_ids @> '[5]', department_ids @> '[2]', visible_to @> '[7]'.
        $this->addSql('CREATE INDEX idx_search_document_executors ON search_document USING gin (executor_ids jsonb_path_ops)');
        $this->addSql('CREATE INDEX idx_search_document_departments ON search_document USING gin (department_ids jsonb_path_ops)');
        $this->addSql('CREATE INDEX idx_search_document_visible ON search_document USING gin (visible_to jsonb_path_ops)');

        $this->addSql('CREATE TABLE plan_read (user_id INT NOT NULL, subject_type VARCHAR(16) NOT NULL, subject_id INT NOT NULL, last_event_id BIGINT NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (user_id, subject_type, subject_id))');
        $this->addSql('CREATE INDEX IDX_8D818D95A76ED395 ON plan_read (user_id)');
        $this->addSql('ALTER TABLE plan_read ADD CONSTRAINT FK_8D818D95A76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE SEQUENCE plan_saved_search_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE plan_saved_search (id INT NOT NULL, user_id INT NOT NULL, name VARCHAR(80) NOT NULL, query VARCHAR(1000) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_plan_saved_search_user ON plan_saved_search (user_id, id)');
        $this->addSql('CREATE INDEX IDX_A62A1B4A76ED395 ON plan_saved_search (user_id)');
        $this->addSql('ALTER TABLE plan_saved_search ADD CONSTRAINT FK_A62A1B4A76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE plan_saved_search');
        $this->addSql('DROP SEQUENCE plan_saved_search_id_seq');
        $this->addSql('DROP TABLE plan_read');
        $this->addSql('DROP TABLE search_document');
        $this->addSql('DROP SEQUENCE search_document_id_seq');
    }
}
