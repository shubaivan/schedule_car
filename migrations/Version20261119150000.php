<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Роль у «Плані» більше не виводиться з ролі в заявках (інакше менеджер постачання робив би
 * людину директором «Плану» повз «Ролі й доступ»). Тим, у кого work_role порожній, проставляємо
 * ту саму роль, що діяла досі (WorkRole::fromSupply) — для людей нічого не змінюється.
 */
final class Version20261119150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'План: явна work_role для всіх (більше не виводиться з supply_role)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE telegram_user SET work_role = CASE supply_role WHEN 'admin' THEN 'admin' WHEN 'director' THEN 'director' WHEN 'manager' THEN 'manager' ELSE 'production' END WHERE work_role IS NULL");
    }

    public function down(Schema $schema): void
    {
        // Безпечно не відкочується: не відомо, у кого роль була порожня.
    }
}
