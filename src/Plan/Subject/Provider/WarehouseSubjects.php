<?php

namespace App\Plan\Subject\Provider;

use App\Entity\TelegramUser;
use App\Plan\Subject\SubjectProvider;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Repository\TelegramUserRepository;
use App\Tasks\Service\TasksSection;
use App\Warehouse\Entity\WhCategory;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Service\ActivityLog;
use App\Warehouse\Service\WarehouseSection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Склад: позиції, рухи, клієнти, об'єкти, категорії. Ведуть менеджер, адмін, директор. */
final class WarehouseSubjects implements SubjectProvider
{
    private const array ENTITIES = [
        'wh_item' => WhItem::class,
        'wh_movement' => WhMovement::class,
        'wh_client' => WhClient::class,
        'wh_site' => WhSite::class,
        'wh_category' => WhCategory::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TelegramUserRepository $users,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?TasksSection $plan = null,
    ) {
    }

    public function supports(SubjectType $type): bool
    {
        return $type->isWarehouse();
    }

    public function title(SubjectRef $ref): ?string
    {
        $entity = $this->entity($ref);

        return $entity !== null ? ActivityLog::labelOf($entity) : null;
    }

    public function url(SubjectRef $ref): string
    {
        // «План» (CRM_HOME=tasks): позиції й рухи відкриваються у Vue-складі оболонки;
        // стенди заявок і решта суб'єктів — у Twig-складі /sklad, як і раніше.
        if ($this->plan?->home() === 'tasks' && in_array($ref->type, [SubjectType::WhItem, SubjectType::WhMovement], true)) {
            $path = $ref->type === SubjectType::WhItem ? 'sklad/items/' : 'sklad/movements/';

            return $this->urls->generate('crm_index', ['path' => $path . $ref->id]);
        }

        return match ($ref->type) {
            SubjectType::WhItem => $this->urls->generate('wh_item', ['id' => $ref->id]),
            SubjectType::WhMovement => $this->urls->generate('wh_movement', ['id' => $ref->id]),
            SubjectType::WhClient => $this->urls->generate('wh_client', ['id' => $ref->id]),
            SubjectType::WhSite => $this->urls->generate('wh_site', ['id' => $ref->id]),
            default => $this->urls->generate('wh_categories'),
        };
    }

    public function audience(SubjectRef $ref): array
    {
        $ids = [];

        foreach ($this->users->findAll() as $user) {
            if (WarehouseSection::canManage($user) && ! $user->isArchived()) {
                $ids[] = (int) $user->getId();
            }
        }

        return $ids;
    }

    public function visibleTo(SubjectRef $ref, TelegramUser $user): bool
    {
        return WarehouseSection::canManage($user) && $this->entity($ref) !== null;
    }

    private function entity(SubjectRef $ref): WhItem|WhMovement|WhClient|WhSite|WhCategory|null
    {
        /** @var class-string<WhItem|WhMovement|WhClient|WhSite|WhCategory> $class */
        $class = self::ENTITIES[$ref->type->value];

        return $this->em->find($class, $ref->id);
    }
}
