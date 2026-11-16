<?php

namespace App\Plan\Subject\Provider;

use App\Entity\TelegramUser;
use App\Plan\Subject\SubjectProvider;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Repository\TelegramUserRepository;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Repository\SupplyVisibility;
use App\Tasks\Service\TasksSection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Заявки: «в темі» — автор і менеджери постачання. Директори долучаються на
 * погодженні — це вже правило сповіщень (ApprovalRequested), не суб'єкта.
 * Бачать заявку на стендах заявок усі допущені до CRM (рішення клієнта 13.08: заявки
 * відкриті); у «Плані» — за SupplyVisibility (хто не присутній — не бачить).
 */
final class SupplySubjects implements SubjectProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TelegramUserRepository $users,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?TasksSection $plan = null,
        private readonly ?SupplyVisibility $visibility = null,
    ) {
    }

    public function supports(SubjectType $type): bool
    {
        return $type === SubjectType::Supply;
    }

    public function title(SubjectRef $ref): ?string
    {
        $request = $this->em->find(SupplyRequest::class, $ref->id);

        return $request !== null ? sprintf('№ %s · %s', $request->getNumber(), $request->getItem()) : null;
    }

    public function url(SubjectRef $ref): string
    {
        // «План» (CRM_HOME=tasks): заявка — у дошці заявок оболонки; стенди заявок — стара картка, як і раніше.
        $path = $this->plan?->home() === 'tasks' ? 'supply/board/' : 'requests/';

        return $this->urls->generate('crm_index', ['path' => $path . $ref->id]);
    }

    public function audience(SubjectRef $ref): array
    {
        $request = $this->em->find(SupplyRequest::class, $ref->id);

        if ($request === null) {
            return [];
        }

        $ids = [(int) $request->getAuthor()->getId()];

        foreach ($this->users->findAll() as $user) {
            if ($user->getSupplyRole()->canManage() && $user->isApproved() && ! $user->isArchived()) {
                $ids[] = (int) $user->getId();
            }
        }

        return array_values(array_unique($ids));
    }

    public function visibleTo(SubjectRef $ref, TelegramUser $user): bool
    {
        if (! $user->isApproved() || $user->isArchived()) {
            return false;
        }

        $request = $this->em->find(SupplyRequest::class, $ref->id);

        return $request !== null && ($this->visibility?->canView($request, $user) ?? true);
    }
}
