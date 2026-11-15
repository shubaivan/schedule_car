<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Огляд» v2: мінімальний залишок для позицій складу «кількістю» і індекси під
 * віджети за датою — закупівлі місяця, броні автопарку на сьогодні/завтра, рухи
 * складу за день/тиждень. Таблиці невеликі, тож звичайний CREATE INDEX.
 */
final class Version20261117090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: wh_item.min_quantity + індекси дат для «Огляду» (закупівлі, броні, рухи складу)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wh_item ADD COLUMN IF NOT EXISTS min_quantity INT DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN wh_item.min_quantity IS 'Мінімальний залишок на складах (лише позиції «кількістю»); нижче — попередження в «Огляді»'");
        $this->addSql('CREATE INDEX IF NOT EXISTS supply_purchase_purchased_idx ON supply_purchase (purchased_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS scheduled_set_at_idx ON scheduled_set (scheduled_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS wh_movement_occurred_idx ON wh_movement (occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS wh_movement_occurred_idx');
        $this->addSql('DROP INDEX IF EXISTS scheduled_set_at_idx');
        $this->addSql('DROP INDEX IF EXISTS supply_purchase_purchased_idx');
        $this->addSql('ALTER TABLE wh_item DROP COLUMN IF EXISTS min_quantity');
    }
}
