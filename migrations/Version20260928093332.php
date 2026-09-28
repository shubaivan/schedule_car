<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
/** Розділ «Склад»: облік майна (опалубка, техніка — будь-що), оренда, QR-наклейки, журнал дій. */
final class Version20260928093332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Склад: довідник категорій, майно з QR, клієнти й обʼєкти, рухи, документи, журнал';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE wh_activity_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE wh_category_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE wh_client_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE wh_document_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE wh_item_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE wh_movement_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE wh_movement_line_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE wh_site_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE wh_activity (id INT NOT NULL, user_id INT DEFAULT NULL, action VARCHAR(16) NOT NULL, subject_type VARCHAR(16) NOT NULL, subject_id INT NOT NULL, subject_label VARCHAR(255) NOT NULL, channel VARCHAR(8) NOT NULL, details VARCHAR(500) DEFAULT NULL, at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX wh_activity_subject_idx ON wh_activity (subject_type, subject_id)');
        $this->addSql('CREATE INDEX wh_activity_user_idx ON wh_activity (user_id)');
        $this->addSql('CREATE INDEX wh_activity_at_idx ON wh_activity (at)');
        $this->addSql('CREATE TABLE wh_category (id INT NOT NULL, scope VARCHAR(16) NOT NULL, name VARCHAR(120) NOT NULL, emoji VARCHAR(16) DEFAULT NULL, prefix VARCHAR(8) DEFAULT NULL, attributes JSON DEFAULT \'[]\' NOT NULL, position INT DEFAULT 0 NOT NULL, active BOOLEAN DEFAULT true NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX wh_category_scope_name_idx ON wh_category (scope, name)');
        $this->addSql('CREATE TABLE wh_client (id INT NOT NULL, category_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, edrpou VARCHAR(16) DEFAULT NULL, phone VARCHAR(32) DEFAULT NULL, contact_person VARCHAR(255) DEFAULT NULL, note TEXT DEFAULT NULL, active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_D2DE3D1412469DE2 ON wh_client (category_id)');
        $this->addSql('CREATE TABLE wh_document (id INT NOT NULL, client_id INT DEFAULT NULL, site_id INT DEFAULT NULL, item_id INT DEFAULT NULL, movement_id INT DEFAULT NULL, uploaded_by_id INT DEFAULT NULL, type VARCHAR(16) NOT NULL, original_name VARCHAR(255) NOT NULL, storage_path VARCHAR(512) NOT NULL, mime VARCHAR(128) NOT NULL, size INT NOT NULL, sha256 VARCHAR(64) NOT NULL, drive_file_id VARCHAR(128) DEFAULT NULL, drive_url VARCHAR(512) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6E0E69A0279401A ON wh_document (storage_path)');
        $this->addSql('CREATE INDEX IDX_6E0E69A0A2B28FE8 ON wh_document (uploaded_by_id)');
        $this->addSql('CREATE INDEX wh_document_client_idx ON wh_document (client_id)');
        $this->addSql('CREATE INDEX wh_document_site_idx ON wh_document (site_id)');
        $this->addSql('CREATE INDEX wh_document_item_idx ON wh_document (item_id)');
        $this->addSql('CREATE INDEX wh_document_movement_idx ON wh_document (movement_id)');
        $this->addSql('CREATE TABLE wh_item (id INT NOT NULL, category_id INT NOT NULL, current_site_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, inventory_number VARCHAR(32) NOT NULL, name VARCHAR(255) NOT NULL, tracking VARCHAR(8) NOT NULL, unit VARCHAR(16) DEFAULT \'шт\' NOT NULL, description TEXT DEFAULT NULL, attributes JSON DEFAULT \'{}\' NOT NULL, serial_number VARCHAR(64) DEFAULT NULL, supplier VARCHAR(255) DEFAULT NULL, purchase_price NUMERIC(12, 2) DEFAULT NULL, purchased_at DATE DEFAULT NULL, rental_rate NUMERIC(12, 2) DEFAULT NULL, state VARCHAR(16) DEFAULT \'active\' NOT NULL, current_since DATE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D4181EF4964C83FF ON wh_item (inventory_number)');
        $this->addSql('CREATE INDEX IDX_D4181EF412469DE2 ON wh_item (category_id)');
        $this->addSql('CREATE INDEX IDX_D4181EF4B03A8386 ON wh_item (created_by_id)');
        $this->addSql('CREATE INDEX wh_item_site_idx ON wh_item (current_site_id)');
        $this->addSql('CREATE TABLE wh_movement (id INT NOT NULL, from_site_id INT DEFAULT NULL, to_site_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, type VARCHAR(16) NOT NULL, occurred_at DATE NOT NULL, document_number VARCHAR(64) DEFAULT NULL, counterparty VARCHAR(255) DEFAULT NULL, note TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_42BA7621B03A8386 ON wh_movement (created_by_id)');
        $this->addSql('CREATE INDEX wh_movement_from_idx ON wh_movement (from_site_id)');
        $this->addSql('CREATE INDEX wh_movement_to_idx ON wh_movement (to_site_id)');
        $this->addSql('CREATE TABLE wh_movement_line (id INT NOT NULL, movement_id INT NOT NULL, item_id INT NOT NULL, quantity INT NOT NULL, rental_rate NUMERIC(12, 2) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_34426B58229E70A7 ON wh_movement_line (movement_id)');
        $this->addSql('CREATE INDEX wh_movement_line_item_idx ON wh_movement_line (item_id)');
        $this->addSql('CREATE TABLE wh_site (id INT NOT NULL, client_id INT DEFAULT NULL, category_id INT DEFAULT NULL, kind VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, address VARCHAR(255) DEFAULT NULL, note TEXT DEFAULT NULL, active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_A240320E12469DE2 ON wh_site (category_id)');
        $this->addSql('CREATE INDEX wh_site_client_idx ON wh_site (client_id)');
        $this->addSql('ALTER TABLE wh_activity ADD CONSTRAINT FK_1A13EA8CA76ED395 FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_client ADD CONSTRAINT FK_D2DE3D1412469DE2 FOREIGN KEY (category_id) REFERENCES wh_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_document ADD CONSTRAINT FK_6E0E69A019EB6921 FOREIGN KEY (client_id) REFERENCES wh_client (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_document ADD CONSTRAINT FK_6E0E69A0F6BD1646 FOREIGN KEY (site_id) REFERENCES wh_site (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_document ADD CONSTRAINT FK_6E0E69A0126F525E FOREIGN KEY (item_id) REFERENCES wh_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_document ADD CONSTRAINT FK_6E0E69A0229E70A7 FOREIGN KEY (movement_id) REFERENCES wh_movement (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_document ADD CONSTRAINT FK_6E0E69A0A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_item ADD CONSTRAINT FK_D4181EF412469DE2 FOREIGN KEY (category_id) REFERENCES wh_category (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_item ADD CONSTRAINT FK_D4181EF45CB093C3 FOREIGN KEY (current_site_id) REFERENCES wh_site (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_item ADD CONSTRAINT FK_D4181EF4B03A8386 FOREIGN KEY (created_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_movement ADD CONSTRAINT FK_42BA762170E3F5E9 FOREIGN KEY (from_site_id) REFERENCES wh_site (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_movement ADD CONSTRAINT FK_42BA762178252BB3 FOREIGN KEY (to_site_id) REFERENCES wh_site (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_movement ADD CONSTRAINT FK_42BA7621B03A8386 FOREIGN KEY (created_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_movement_line ADD CONSTRAINT FK_34426B58229E70A7 FOREIGN KEY (movement_id) REFERENCES wh_movement (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_movement_line ADD CONSTRAINT FK_34426B58126F525E FOREIGN KEY (item_id) REFERENCES wh_item (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_site ADD CONSTRAINT FK_A240320E19EB6921 FOREIGN KEY (client_id) REFERENCES wh_client (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wh_site ADD CONSTRAINT FK_A240320E12469DE2 FOREIGN KEY (category_id) REFERENCES wh_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Основний склад існує з першого дня: без нього нікуди прийняти перше надходження.
        $this->addSql("INSERT INTO wh_site (id, kind, name, active, created_at, updated_at) VALUES (nextval('wh_site_id_seq'), 'warehouse', 'Основний склад', true, NOW(), NOW())");

        // Стартовий довідник — далі менеджер доповнює його в адмінці сам.
        $categories = [
            ['item', 'Опалубка', '🧱', 'ОП', '["Розміри", "Вага", "Матеріал"]'],
            ['item', 'Техніка', '🚜', 'ТХ', '["Держномер", "Рік випуску", "Моточаси"]'],
            ['item', 'Обладнання', '⚙️', 'ОБ', '["Потужність", "Рік випуску"]'],
            ['item', 'Риштування', '🪜', 'РШ', '["Розміри", "Вага"]'],
            ['item', 'Інструмент', '🔧', 'ІН', '[]'],
            ['item', 'Інше', '📦', 'ІШ', '[]'],
            ['client', 'Забудовник', '🏢', null, '[]'],
            ['client', 'Підрядник', '👷', null, '[]'],
            ['client', 'Приватна особа', '👤', null, '[]'],
            ['site', 'Житловий комплекс', '🏘', null, '[]'],
            ['site', 'Приватний будинок', '🏡', null, '[]'],
            ['site', 'Комерційний обʼєкт', '🏬', null, '[]'],
            ['site', 'Інфраструктура', '🛣', null, '[]'],
        ];

        foreach ($categories as $position => [$scope, $name, $emoji, $prefix, $attributes]) {
            $this->addSql(
                "INSERT INTO wh_category (id, scope, name, emoji, prefix, attributes, position, active) VALUES (nextval('wh_category_id_seq'), ?, ?, ?, ?, ?, ?, true)",
                [$scope, $name, $emoji, $prefix, $attributes, $position],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE wh_activity_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE wh_category_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE wh_client_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE wh_document_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE wh_item_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE wh_movement_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE wh_movement_line_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE wh_site_id_seq CASCADE');
        $this->addSql('ALTER TABLE wh_activity DROP CONSTRAINT FK_1A13EA8CA76ED395');
        $this->addSql('ALTER TABLE wh_client DROP CONSTRAINT FK_D2DE3D1412469DE2');
        $this->addSql('ALTER TABLE wh_document DROP CONSTRAINT FK_6E0E69A019EB6921');
        $this->addSql('ALTER TABLE wh_document DROP CONSTRAINT FK_6E0E69A0F6BD1646');
        $this->addSql('ALTER TABLE wh_document DROP CONSTRAINT FK_6E0E69A0126F525E');
        $this->addSql('ALTER TABLE wh_document DROP CONSTRAINT FK_6E0E69A0229E70A7');
        $this->addSql('ALTER TABLE wh_document DROP CONSTRAINT FK_6E0E69A0A2B28FE8');
        $this->addSql('ALTER TABLE wh_item DROP CONSTRAINT FK_D4181EF412469DE2');
        $this->addSql('ALTER TABLE wh_item DROP CONSTRAINT FK_D4181EF45CB093C3');
        $this->addSql('ALTER TABLE wh_item DROP CONSTRAINT FK_D4181EF4B03A8386');
        $this->addSql('ALTER TABLE wh_movement DROP CONSTRAINT FK_42BA762170E3F5E9');
        $this->addSql('ALTER TABLE wh_movement DROP CONSTRAINT FK_42BA762178252BB3');
        $this->addSql('ALTER TABLE wh_movement DROP CONSTRAINT FK_42BA7621B03A8386');
        $this->addSql('ALTER TABLE wh_movement_line DROP CONSTRAINT FK_34426B58229E70A7');
        $this->addSql('ALTER TABLE wh_movement_line DROP CONSTRAINT FK_34426B58126F525E');
        $this->addSql('ALTER TABLE wh_site DROP CONSTRAINT FK_A240320E19EB6921');
        $this->addSql('ALTER TABLE wh_site DROP CONSTRAINT FK_A240320E12469DE2');
        $this->addSql('DROP TABLE wh_activity');
        $this->addSql('DROP TABLE wh_category');
        $this->addSql('DROP TABLE wh_client');
        $this->addSql('DROP TABLE wh_document');
        $this->addSql('DROP TABLE wh_item');
        $this->addSql('DROP TABLE wh_movement');
        $this->addSql('DROP TABLE wh_movement_line');
        $this->addSql('DROP TABLE wh_site');
    }
}
