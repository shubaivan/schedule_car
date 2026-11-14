<?php

namespace App\Plan\Search;

use App\Entity\TelegramUser;
use App\Plan\Subject\SubjectRef;
use App\Tasks\Service\TasksSection;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Люди в пошуку: картку співробітника змінюють багато місць (CRM, бот,
 * підтвердження доступу, архів) і не всі пишуть подію «Плану», тож документ
 * «людина» оновлюємо після кожного flush, у якому змінився TelegramUser.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class PersonIndexListener
{
    /** Поля, з яких складається документ «людина». */
    private const INDEXED = ['first_name', 'last_name', 'username', 'position', 'department', 'workRole', 'supplyRole', 'accessStatus', 'archivedAt', 'email'];

    /** @var array<int, TelegramUser> змінені/нові люди (id нових з'явиться лише після INSERT) */
    private array $pending = [];

    /** @var array<int, true> id, що вже видалені (документ треба прибрати) */
    private array $removed = [];

    public function __construct(private readonly SearchProjector $projector, private readonly LoggerInterface $logger, private readonly TasksSection $plan)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (! $this->plan->enabled()) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof TelegramUser) {
                $this->pending[spl_object_id($entity)] = $entity;
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            // «Остання активність» (раз на 5 хв) тощо — документ людини не змінює.
            if ($entity instanceof TelegramUser && array_intersect(array_keys($uow->getEntityChangeSet($entity)), self::INDEXED) !== []) {
                $this->pending[spl_object_id($entity)] = $entity;
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof TelegramUser && $entity->getId() !== null) {
                $this->removed[(int) $entity->getId()] = true;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $pending = $this->pending;
        $removed = $this->removed;
        $this->pending = $this->removed = [];

        $ids = array_keys($removed);

        foreach ($pending as $user) {
            if ($user->getId() !== null) {
                $ids[] = (int) $user->getId();
            }
        }

        foreach (array_unique($ids) as $id) {
            try {
                $this->projector->projectSubject(SubjectRef::user($id));
            } catch (Throwable $e) {
                // Пошук — не причина зламати збереження картки; reindex вирівняє.
                $this->logger->warning('plan search: не оновився документ людини', ['user' => $id, 'error' => $e->getMessage()]);
            }
        }
    }
}
