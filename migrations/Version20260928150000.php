<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Supply\Entity\Supplier;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Постачальник на складі — зі спільного довідника заявок, а не рядком.
 *
 * Уже записані рядки (wh_item.supplier, wh_movement.counterparty) не губляться:
 * кожен знаходить свого постачальника за нормалізованою назвою
 * (Supplier::normalize — те саме правило, що в довіднику) або заводить нового.
 */
final class Version20260928150000 extends AbstractMigration
{
    private const COLUMNS = [
        // таблиця => старий рядковий стовпець
        'wh_item' => 'supplier',
        'wh_movement' => 'counterparty',
    ];

    public function getDescription(): string
    {
        return 'Склад: постачальник позиції й надходження — посилання на supply_supplier';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wh_item ADD supplier_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE wh_item ADD CONSTRAINT FK_D4181EF42ADD6D8C FOREIGN KEY (supplier_id) REFERENCES supply_supplier (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_D4181EF42ADD6D8C ON wh_item (supplier_id)');
        $this->addSql('ALTER TABLE wh_movement ADD supplier_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE wh_movement ADD CONSTRAINT FK_42BA76212ADD6D8C FOREIGN KEY (supplier_id) REFERENCES supply_supplier (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_42BA76212ADD6D8C ON wh_movement (supplier_id)');
    }

    /** Дані переносимо після того, як нові стовпці вже є, і лише тоді прибираємо старі. */
    public function postUp(Schema $schema): void
    {
        $known = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, name_normalized FROM supply_supplier') as $row) {
            $known[$row['name_normalized']] = (int) $row['id'];
        }

        foreach (self::COLUMNS as $table => $column) {
            // Найчастіше написання — першим: воно й стане назвою в довіднику.
            $names = $this->connection->fetchFirstColumn(sprintf(
                "SELECT %s FROM %s WHERE %s IS NOT NULL AND TRIM(%s) <> '' GROUP BY %s ORDER BY COUNT(*) DESC, %s",
                $column, $table, $column, $column, $column, $column,
            ));

            foreach ($names as $name) {
                $key = Supplier::normalize((string) $name);

                if ($key === '') {
                    continue;
                }

                if (! isset($known[$key])) {
                    $id = (int) $this->connection->fetchOne("SELECT nextval('supply_supplier_id_seq')");
                    $this->connection->insert('supply_supplier', [
                        'id' => $id,
                        'name' => trim((string) $name),
                        'name_normalized' => $key,
                        'active' => true,
                        'created_at' => date('Y-m-d H:i:s'),
                    ], ['active' => 'boolean']);
                    $known[$key] = $id;
                }

                $this->connection->executeStatement(
                    sprintf('UPDATE %s SET supplier_id = ? WHERE %s = ?', $table, $column),
                    [$known[$key], $name],
                );
            }

            $this->connection->executeStatement(sprintf('ALTER TABLE %s DROP %s', $table, $column));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wh_item ADD supplier VARCHAR(255) DEFAULT NULL');
        $this->addSql('UPDATE wh_item i SET supplier = s.name FROM supply_supplier s WHERE s.id = i.supplier_id');
        $this->addSql('ALTER TABLE wh_item DROP CONSTRAINT FK_D4181EF42ADD6D8C');
        $this->addSql('DROP INDEX IDX_D4181EF42ADD6D8C');
        $this->addSql('ALTER TABLE wh_item DROP supplier_id');
        $this->addSql('ALTER TABLE wh_movement ADD counterparty VARCHAR(255) DEFAULT NULL');
        $this->addSql('UPDATE wh_movement m SET counterparty = s.name FROM supply_supplier s WHERE s.id = m.supplier_id');
        $this->addSql('ALTER TABLE wh_movement DROP CONSTRAINT FK_42BA76212ADD6D8C');
        $this->addSql('DROP INDEX IDX_42BA76212ADD6D8C');
        $this->addSql('ALTER TABLE wh_movement DROP supplier_id');
    }
}
