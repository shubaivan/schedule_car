<?php

namespace App\Plan\Subject\Provider;

use App\Entity\TelegramUser;
use App\Plan\Subject\SubjectProvider;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Repository\TelegramUserRepository;
use App\Supply\Entity\SupplyRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Заявки: «в темі» — автор і менеджери постачання. Директори долучаються на
 * погодженні — це вже правило сповіщень (ApprovalRequested), не суб'єкта.
 * Бачать заявку всі допущені до CRM (рішення клієнта 13.08: заявки відкриті).
 */
final class SupplySubjects implements SubjectProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TelegramUserRepository $users,
        private readonly UrlGeneratorInterface $urls,
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
        return $this->urls->generate('crm_index', ['path' => 'requests/' . $ref->id]);
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
        return $user->isApproved() && ! $user->isArchived() && $this->em->find(SupplyRequest::class, $ref->id) !== null;
    }
}
