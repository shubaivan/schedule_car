<?php

namespace App\Plan\Access;

use App\Entity\TelegramUser;
use App\Plan\Event\RolePermissionChanged;
use App\Plan\Event\StaffChanged;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Tasks\Enum\WorkRole;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Сторінка «Ролі й доступ» (Олександр 17.11): матриця «роль × право» (RolePermission) з
 * таблиці plan_role_permission поверх значень з коду, і суперадміністратори.
 *
 * Безпека:
 * - керують лише адміністратор, системний адміністратор і суперадмін (managesRoles);
 * - зафіксовані клітинки (системний адмін без задач/чатів, адмін веде людей) не змінюються;
 * - «Приховані розділи» ролі й суперадмінів змінює лише суперадмін;
 * - останнього суперадміна зняти не можна; себе — теж ні (лише інший суперадмін чи консоль);
 * - кожна зміна — в журнал аудиту (RolePermissionChanged / StaffChanged) і в історію сторінки.
 */
class RolePermissions
{
    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly PlanEventRecorder $events,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Підключити таблицю до RoleGrants (RoleGrantsReset — на кожен запит, команду, повідомлення воркера). */
    public function register(): void
    {
        RoleGrants::useLoader($this->load(...));
    }

    /** @return array<string, array<string, bool>> */
    public function load(): array
    {
        $out = [];

        foreach ($this->db->fetchAllAssociative('SELECT role, permission, allowed FROM plan_role_permission') as $row) {
            $out[(string) $row['role']][(string) $row['permission']] = (bool) $row['allowed'];
        }

        return $out;
    }

    /**
     * Матриця для сторінки: ролі, права (групи), значення, зафіксовані клітинки, що змінено.
     *
     * @return array<string, mixed>
     */
    public function matrix(TelegramUser $viewer): array
    {
        $this->register();
        $overrides = $this->load();
        $roles = WorkRole::cases();

        return [
            'roles' => array_map(static fn (WorkRole $r) => ['value' => $r->value, 'label' => $r->label()], $roles),
            'permissions' => array_map(fn (RolePermission $p) => [
                'value' => $p->value,
                'label' => $p->label(),
                'group' => $p->group(),
                'superadminOnly' => $p->superadminOnly(),
                'cells' => array_map(fn (WorkRole $r) => [
                    'role' => $r->value,
                    'allowed' => RoleGrants::allows($r, $p),
                    'default' => $p->byDefault($r),
                    'changed' => $p->locked($r) === null && isset($overrides[$r->value][$p->value]) && $overrides[$r->value][$p->value] !== $p->byDefault($r),
                    'locked' => $p->locked($r) !== null,
                    'lockReason' => $p->lockReason($r),
                    'editable' => $this->canEdit($viewer, $r, $p),
                ], $roles),
            ], RolePermission::cases()),
            // Не налаштовується — для довідки на сторінці.
            'fixed' => [
                ['label' => 'Ролі й доступ (ця сторінка)', 'roles' => [WorkRole::Admin->value, WorkRole::SysAdmin->value], 'note' => 'і суперадміністратор'],
                ['label' => 'Заявки на приєднання', 'roles' => [WorkRole::SysAdmin->value], 'note' => 'і суперадміністратор'],
                ['label' => 'Журнал аудиту', 'roles' => [], 'note' => 'лише суперадміністратор: у журналі уривки задач'],
            ],
        ];
    }

    public function canEdit(TelegramUser $by, WorkRole $role, RolePermission $permission): bool
    {
        if (! $by->managesRoles() || $permission->locked($role) !== null) {
            return false;
        }

        if ($permission->superadminOnly() && ! $by->isSuperadmin()) {
            return false;
        }

        $tasks = in_array($permission, [RolePermission::TasksAccess, RolePermission::TasksSeeAll, RolePermission::TasksEditAny, RolePermission::TasksDecide, RolePermission::LeadsReceive], true);

        if ($permission === RolePermission::Zvyazok && ! $by->canUseZvyazok()) {
            return false;
        }

        // Права на задачі й ліди роздає лише той, хто бачить усі задачі (не системний адмін).
        return ! $tasks || $by->seesAllTasks();
    }

    public function set(TelegramUser $by, WorkRole $role, RolePermission $permission, bool $allowed): bool
    {
        if (! $this->canEdit($by, $role, $permission)) {
            $this->logger->warning('roles: спроба змінити право без дозволу', ['by' => $by->getId(), 'role' => $role->value, 'permission' => $permission->value]);

            throw new AccessDeniedException($permission->locked($role) !== null
                ? (string) $permission->lockReason($role)
                : 'Це право змінює лише суперадміністратор.');
        }

        $this->register();
        $before = RoleGrants::allows($role, $permission);

        if ($before === $allowed) {
            return false;
        }

        $this->events->transactional(function () use ($by, $role, $permission, $allowed, $before): void {
            if ($allowed === $permission->byDefault($role)) {
                // Повернули як у коді — рядок не потрібен.
                $this->db->executeStatement('DELETE FROM plan_role_permission WHERE role = ? AND permission = ?', [$role->value, $permission->value]);
            } else {
                $this->db->executeStatement(
                    'INSERT INTO plan_role_permission (role, permission, allowed, updated_at, updated_by_id) VALUES (:role, :perm, :allowed, :at, :by)
                     ON CONFLICT (role, permission) DO UPDATE SET allowed = EXCLUDED.allowed, updated_at = EXCLUDED.updated_at, updated_by_id = EXCLUDED.updated_by_id',
                    ['role' => $role->value, 'perm' => $permission->value, 'allowed' => $allowed ? 'true' : 'false', 'at' => new DateTimeImmutable()->format('Y-m-d H:i:s'), 'by' => $by->getId()],
                );
            }

            $this->events->record(new RolePermissionChanged(SubjectRef::user((int) $by->getId()), $by->getId(), $role->value, $permission->value, $allowed, $before));
        });

        RoleGrants::reset();
        $this->logger->info('roles: право змінено', ['by' => $by->getId(), 'role' => $role->value, 'permission' => $permission->value, 'allowed' => $allowed]);

        return true;
    }

    /** Суперадміністратора видає й знімає лише суперадмін; останнього й себе — не зняти. */
    public function superadmin(TelegramUser $by, TelegramUser $target, bool $grant): bool
    {
        if (! $by->isSuperadmin()) {
            throw new AccessDeniedException('Суперадміністраторів призначає лише суперадміністратор.');
        }

        if ($target->isSuperadmin() === $grant) {
            return false;
        }

        if ($grant && ($target->isArchived() || ! $target->isApproved())) {
            throw new AccessDeniedException('Людина в кошику або без підтвердженого доступу — суперадміністратором не стане.');
        }

        if (! $grant && $target->getId() === $by->getId()) {
            throw new AccessDeniedException('Себе зняти не можна — це зробить інший суперадміністратор.');
        }

        $this->events->transactional(function () use ($by, $target, $grant): void {
            // Під блокуванням рядків: два суперадміни одночасно знімають один одного — хоч один лишиться.
            if (! $grant) {
                $active = $this->db->fetchFirstColumn("SELECT id FROM telegram_user WHERE is_superadmin = TRUE AND archived_at IS NULL AND access_status = 'approved' FOR UPDATE");

                if (count(array_diff(array_map('intval', $active), [(int) $target->getId()])) < 1) {
                    throw new AccessDeniedException('Це останній суперадміністратор — його не зняти.');
                }
            }

            $target->setSuperadmin($grant);
            $this->em->flush();
            $this->events->record(new StaffChanged(SubjectRef::user((int) $target->getId()), $by->getId(), action: $grant ? 'superadmin_grant' : 'superadmin_revoke'));
        });

        $this->logger->info('roles: суперадмін', ['by' => $by->getId(), 'target' => $target->getId(), 'grant' => $grant]);

        return true;
    }

    /**
     * Історія змін ролей і прав для сторінки (без журналу аудиту — його бачить лише суперадмін):
     * лише події ролей, без уривків задач.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 100): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT e.id, e.type, e.actor_id, e.subject_id, e.payload, e.occurred_at FROM plan_event e
              WHERE e.type = 'role_permission_changed'
                 OR (e.type = 'staff_changed' AND (e.payload->>'roleTo' IS NOT NULL AND e.payload->>'roleTo' <> '' OR e.payload->>'action' IN ('superadmin_grant', 'superadmin_revoke')))
              ORDER BY e.id DESC LIMIT " . max(1, min(500, $limit)),
        );
        $ids = array_values(array_unique(array_filter(array_map('intval', [...array_column($rows, 'actor_id'), ...array_column($rows, 'subject_id')]))));
        $names = [];

        foreach ($ids === [] ? [] : $this->em->getRepository(TelegramUser::class)->findBy(['id' => $ids]) as $u) {
            $names[(int) $u->getId()] = $u->shortName();
        }

        return array_map(static function (array $r) use ($names): array {
            $p = json_decode((string) $r['payload'], true) ?: [];
            $role = static fn (?string $v): string => $v !== null && $v !== '' ? (WorkRole::tryFrom($v)?->label() ?? $v) : '';

            $text = match (true) {
                $r['type'] === 'role_permission_changed' => sprintf(
                    '%s · %s: %s',
                    $role($p['role'] ?? ''),
                    RolePermission::tryFrom((string) ($p['permission'] ?? ''))?->label() ?? (string) ($p['permission'] ?? ''),
                    ($p['allowed'] ?? false) === true ? 'дозволено' : 'заборонено',
                ),
                ($p['action'] ?? '') === 'superadmin_grant' => sprintf('%s — видано права суперадміністратора', $names[(int) $r['subject_id']] ?? '#' . $r['subject_id']),
                ($p['action'] ?? '') === 'superadmin_revoke' => sprintf('%s — знято права суперадміністратора', $names[(int) $r['subject_id']] ?? '#' . $r['subject_id']),
                default => sprintf('%s — роль: %s%s', $names[(int) $r['subject_id']] ?? '#' . $r['subject_id'], ($p['roleFrom'] ?? '') !== '' ? $role($p['roleFrom']) . ' → ' : '', $role($p['roleTo'] ?? '')),
            };

            return [
                'id' => (int) $r['id'],
                'at' => (string) $r['occurred_at'],
                'by' => $r['actor_id'] !== null ? ($names[(int) $r['actor_id']] ?? '#' . $r['actor_id']) : 'консоль',
                'text' => $text,
            ];
        }, $rows);
    }
}
