<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Access\PlanAccess;
use App\Plan\Access\PlanAccessVoter;
use App\Plan\Access\PlanCapability;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Enum\WorkRole;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** Матриця можливостей «Плану» повторює правила модулів, а voter їх віддає Security. */
class PlanAccessTest extends TestCase
{
    public function testMatrixFollowsModuleRules(): void
    {
        $access = new PlanAccess();

        $director = $this->user(WorkRole::Director, SupplyRole::Director);
        self::assertTrue($access->can($director, PlanCapability::TasksSeeAll));
        self::assertTrue($access->can($director, PlanCapability::SupplyApprove));
        self::assertFalse($access->can($director, PlanCapability::SupplyManage));
        self::assertTrue($access->can($director, PlanCapability::WarehouseView));
        self::assertTrue($access->can($director, PlanCapability::LeadsReceive));
        self::assertFalse($access->can($director, PlanCapability::AuditView), 'Аудит — лише суперадмін');

        // Ревʼю UX №6: продажі й бухгалтерія бачать склад на читання, записують лише ті, хто його веде.
        foreach ([WorkRole::Manager, WorkRole::Accountant] as $role) {
            $reader = $this->user($role, SupplyRole::Worker);
            self::assertTrue($access->can($reader, PlanCapability::WarehouseView), $role->value);
            self::assertFalse($access->can($reader, PlanCapability::WarehouseEdit), $role->value);
            self::assertFalse($access->can($reader, PlanCapability::SupplyManage), $role->value . ': склад не відкриває заявки');
        }
        $keeper = $this->user(WorkRole::Production, SupplyRole::Manager);
        self::assertTrue($access->can($keeper, PlanCapability::WarehouseEdit), 'менеджер постачання веде склад');

        $worker = $this->user(WorkRole::Production, SupplyRole::Worker);
        self::assertSame(array_fill_keys(array_map(static fn ($c) => $c->value, PlanCapability::cases()), false), $access->matrix($worker));

        $super = $this->user(WorkRole::Production, SupplyRole::Worker)->setSuperadmin(true);
        self::assertTrue($access->can($super, PlanCapability::AuditView));
        self::assertContains('ROLE_PLAN_SUPERADMIN', $super->getRoles());
        self::assertNotContains('ROLE_PLAN_SUPERADMIN', $worker->getRoles());

        $pending = new TelegramUser()->setTelegramId('p')->setWorkRole(WorkRole::Director);
        self::assertFalse($access->can($pending, PlanCapability::TasksSeeAll), 'Не допущений — нічого');
        self::assertFalse($access->can(null, PlanCapability::TasksSeeAll));
    }

    public function testVoterAttributes(): void
    {
        self::assertSame('PLAN_TASKS_SEE_ALL', PlanCapability::TasksSeeAll->attribute());
        self::assertSame('PLAN_WAREHOUSE_VIEW', PlanCapability::WarehouseView->attribute());
        self::assertSame(PlanCapability::LeadsReceive, PlanCapability::fromAttribute('PLAN_LEADS_RECEIVE'));

        $voter = new PlanAccessVoter(new PlanAccess());
        $manager = $this->user(WorkRole::Manager, SupplyRole::Manager);
        $token = new UsernamePasswordToken($manager, 'main', $manager->getRoles());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, null, ['PLAN_SUPPLY_MANAGE']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, null, ['PLAN_AUDIT_VIEW']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['ROLE_SUPPLY_MANAGER']));
    }

    private function user(WorkRole $work, SupplyRole $supply): TelegramUser
    {
        $user = new TelegramUser()->setTelegramId('acc-' . uniqid())->setWorkRole($work)->setSupplyRole($supply);
        $user->decideAccess(AccessStatus::Approved, null);

        return $user;
    }
}
