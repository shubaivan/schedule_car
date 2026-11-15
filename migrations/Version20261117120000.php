<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Рішення директора в задачах прибрано (15.11.2026): задачі, що стояли «На погодженні»,
 * «Погоджено» чи «Очікування», повертаються в «В роботі» — у тій самій колонці дошки.
 * Історія переходів (task_message, plan_event) не змінюється. Заявки постачання не чіпаємо.
 */
final class Version20261117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: задачі зі статусів погодження директором → «В роботі»';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE task SET status = 'in_work' WHERE status IN ('approval', 'approved', 'waiting')");
    }

    public function down(Schema $schema): void
    {
        // Попередні статуси не зберігались — назад не відновлюємо.
    }
}
