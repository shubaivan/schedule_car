<?php

namespace App\Supply\Repository;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanSections;
use App\Supply\Entity\SupplyRequest;
use App\Tasks\Service\TasksSection;
use Doctrine\DBAL\Connection;

/**
 * Хто бачить заявку постачання — те саме правило, що й для задач (Олександр, 15.11.2026):
 * хто не присутній у заявці й не веде/не погоджує постачання — не бачить її ніде
 * (Огляд, дошка, список, пошук, Хроніка, посилання).
 *
 * Бачать усі: директор і адмін (seesAllTasks), менеджер постачання (веде чужі заявки),
 * той, хто погоджує оплату. Решта — заявки, де вони автор або писали коментар
 * (як PlanChronicle::supplyOwnSql).
 *
 * Лише в «Плані» (TASKS_ENABLED=1). Стенди заявок лишаються як були — там заявки
 * відкриті всім допущеним (рішення клієнта 13.08).
 */
final class SupplyVisibility
{
    public function __construct(
        private readonly TasksSection $plan,
        private readonly Connection $db,
        private readonly ?PlanSections $sections = null,
    ) {
    }

    /** «Постачання» — прихований розділ (PlanSections): без повного доступу заявок не видно ніде. */
    public function hidden(TelegramUser $viewer): bool
    {
        return $this->sections !== null && ! $this->sections->full($viewer);
    }

    public function seesAll(TelegramUser $viewer): bool
    {
        if ($this->hidden($viewer)) {
            return false;
        }

        // Ролі напряму (як SupplyRole у модулі заявок), а не PlanAccess: допуск до CRM перевіряє файрвол.
        $supply = $viewer->getSupplyRole();

        return ! $this->plan->enabled() || $viewer->seesAllTasks() || $supply->canManage() || $supply->canApprovePayment();
    }

    public function canView(SupplyRequest $request, TelegramUser $viewer): bool
    {
        if ($this->hidden($viewer)) {
            return false;
        }

        if ($this->seesAll($viewer)) {
            return true;
        }

        if ($request->getAuthor()->getId() === $viewer->getId()) {
            return true;
        }

        return $request->getId() !== null && (bool) $this->db->fetchOne(
            'SELECT 1 FROM supply_comment WHERE request_id = ? AND author_id = ? LIMIT 1',
            [(int) $request->getId(), (int) $viewer->getId()],
        );
    }

    /**
     * Умова сирого SQL для виразу з id заявки (напр. `id`, `r.id`, `d.subject_id`).
     *
     * @return array{0: string, 1: array<string, int>}
     */
    public function sql(TelegramUser $viewer, string $idExpr): array
    {
        if ($this->hidden($viewer)) {
            return ['FALSE', []];
        }

        if ($this->seesAll($viewer)) {
            return ['TRUE', []];
        }

        return [
            sprintf(
                '(EXISTS (SELECT 1 FROM supply_request svr WHERE svr.id = %1$s AND svr.author_id = :svViewer)
                  OR EXISTS (SELECT 1 FROM supply_comment svc WHERE svc.request_id = %1$s AND svc.author_id = :svViewer))',
                $idExpr,
            ),
            ['svViewer' => (int) $viewer->getId()],
        ];
    }
}
