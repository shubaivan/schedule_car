<?php

namespace App\Plan\Subject\Provider;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanAccess;
use App\Plan\Access\PlanCapability;
use App\Plan\Counterparty\Entity\Counterparty;
use App\Plan\Subject\SubjectProvider;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Контрагент як суб'єкт журналу: створення, зміни реквізитів, архів — у
 * plan_event (аудит). Сповіщень за ним поки немає (audience порожня): картку
 * правлять щодня, і «змінено телефон» нікому не потрібне в дзвіночку.
 */
final class CounterpartySubjects implements SubjectProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        private readonly PlanAccess $access,
    ) {
    }

    public function supports(SubjectType $type): bool
    {
        return $type === SubjectType::Counterparty;
    }

    public function title(SubjectRef $ref): ?string
    {
        return $this->em->find(Counterparty::class, $ref->id)?->displayName();
    }

    public function url(SubjectRef $ref): string
    {
        return $this->urls->generate('crm_index', ['path' => 'counterparties/' . $ref->id]);
    }

    public function audience(SubjectRef $ref): array
    {
        return [];
    }

    public function visibleTo(SubjectRef $ref, TelegramUser $user): bool
    {
        return $this->access->can($user, PlanCapability::CounterpartiesView);
    }
}
