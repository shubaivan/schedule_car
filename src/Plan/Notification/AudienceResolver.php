<?php

namespace App\Plan\Notification;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanAccess;
use App\Plan\Access\PlanCapability;
use App\Plan\Event\ApprovalRequested;
use App\Plan\Event\CommentAdded;
use App\Plan\Event\FileAdded;
use App\Plan\Event\MemberAdded;
use App\Plan\Event\PlanEvent;
use App\Plan\Event\PurchaseRecorded;
use App\Plan\Notification\Entity\NotificationSetting;
use App\Plan\Subject\SubjectRegistry;
use App\Plan\Subject\SubjectType;
use App\Repository\TelegramUserRepository;
use App\Warehouse\Service\WarehouseSection;
use Doctrine\DBAL\Connection;

/**
 * Хто отримує сповіщення про подію — одне правило для центру, тосту, push
 * (і та сама логіка «в темі», що в Telegram-нотифаєрах).
 *
 * - задача: автор, учасники (виконавці), спостерігачі ★; «вас додали» — лише доданим;
 *   погодження — ще й ті, хто вирішує (директор, адмін);
 * - заявка: автор, менеджери постачання, усі, хто коментував; погодження — ще й
 *   ті, хто погоджує оплату (директор, адмін);
 * - склад: ті, хто його веде (менеджер, директор, адмін);
 * - лід: ті, хто має `leads.receive`;
 * - директор/адмін з налаштуванням «Усе» — ще й усі події задач і заявок (лише в центрі).
 *
 * Завжди: крім автора дії; лише підтверджені й не в кошику; лише ті, кому
 * суб'єкт видно (SubjectProvider::visibleTo) — у сповіщення нічого поза правами
 * не потрапляє. Згаданий у коментарі — одне сповіщення «вас згадали», не два.
 */
final class AudienceResolver
{
    public function __construct(
        private readonly SubjectRegistry $subjects,
        private readonly TelegramUserRepository $users,
        private readonly PlanAccess $access,
        private readonly Connection $db,
    ) {
    }

    /** @return array<int, Recipient> userId → одержувач */
    public function resolve(PlanEvent $event): array
    {
        $group = EventGroup::forEvent($event);

        if ($group === null || ! $this->notifies($event)) {
            return [];
        }

        $type = $event->subject->type;
        // Погодження: «потрібне рішення» — лише тим, хто вирішує; решті учасників це просто зміна статусу.
        $base = $event instanceof ApprovalRequested ? EventGroup::Status : $group;
        /** @var array<int, Recipient> $out */
        $out = [];
        $add = static function (iterable $ids, EventGroup $as, bool $involved = true) use (&$out): void {
            foreach ($ids as $id) {
                $id = (int) $id;
                $current = $out[$id] ?? null;

                // Важливіша група перемагає («вас згадали» над «коментар», «рішення» над «статус»).
                $out[$id] = $current === null
                    ? new Recipient($id, $as, $involved)
                    : new Recipient($id, $as->important() && ! $current->important() ? $as : $current->group, $current->involved || $involved);
            }
        };

        if ($type === SubjectType::Lead) {
            $add($this->capable(PlanCapability::LeadsReceive), $group);
        } elseif ($type->isWarehouse()) {
            $add(array_map(static fn (TelegramUser $u) => (int) $u->getId(), array_filter($this->users->findPeople(), WarehouseSection::canManage(...))), $group);
        } elseif ($type === SubjectType::Task || $type === SubjectType::Supply) {
            if ($event instanceof MemberAdded) {
                $add($event->userIds, $base);
            } else {
                $add($this->subjects->audience($event->subject), $base);

                if ($type === SubjectType::Supply) {
                    $add($this->db->fetchFirstColumn('SELECT DISTINCT author_id FROM supply_comment WHERE request_id = ? AND author_id IS NOT NULL', [$event->subject->id]), $base);
                }
            }

            if ($event instanceof CommentAdded && $event->mentions !== []) {
                $add($event->mentions, EventGroup::Mention);
            }

            if ($event instanceof ApprovalRequested) {
                $add($this->capable($type === SubjectType::Task ? PlanCapability::TasksSeeAll : PlanCapability::SupplyApprove), EventGroup::Approval);
            }

            $add($this->watchingEverything(), $base, involved: false);
        } else {
            return [];
        }

        unset($out[(int) $event->actorId]);

        return $this->visibleOnly($event, $out);
    }

    /** Подія, яку сервіс явно позначив «без сповіщення» (тиха правка закупівлі, службовий файл). */
    private function notifies(PlanEvent $event): bool
    {
        return ! (($event instanceof PurchaseRecorded || $event instanceof FileAdded) && ! $event->notify);
    }

    /** @return list<int> */
    private function capable(PlanCapability $capability): array
    {
        $ids = [];

        foreach ($this->users->findPeople() as $user) {
            if ($this->access->can($user, $capability)) {
                $ids[] = (int) $user->getId();
            }
        }

        return $ids;
    }

    /**
     * Директор/адмін, що ввімкнули «Усе», — події всіх задач і заявок у центрі.
     *
     * @return list<int>
     */
    private function watchingEverything(): array
    {
        $ids = array_map('intval', $this->db->fetchFirstColumn('SELECT user_id FROM notification_setting WHERE scope = ?', [NotificationSetting::SCOPE_ALL]));

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach ($this->users->findBy(['id' => $ids]) as $user) {
            if ($this->access->can($user, PlanCapability::TasksSeeAll)) {
                $out[] = (int) $user->getId();
            }
        }

        return $out;
    }

    /**
     * @param array<int, Recipient> $recipients
     *
     * @return array<int, Recipient>
     */
    private function visibleOnly(PlanEvent $event, array $recipients): array
    {
        if ($recipients === []) {
            return [];
        }

        $hasProvider = $this->subjects->has($event->subject->type);
        $out = [];

        foreach ($this->users->findBy(['id' => array_keys($recipients)]) as $user) {
            if (! $user->isApproved() || $user->isArchived()) {
                continue;
            }

            // Лід (потік SL ще без провайдера) — право дає сама можливість leads.receive.
            if ($hasProvider ? $this->subjects->visibleTo($event->subject, $user) : $this->access->can($user, PlanCapability::LeadsReceive)) {
                $out[(int) $user->getId()] = $recipients[(int) $user->getId()];
            }
        }

        ksort($out);

        return $out;
    }
}
