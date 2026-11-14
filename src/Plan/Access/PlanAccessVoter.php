<?php

namespace App\Plan\Access;

use App\Entity\TelegramUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * `#[IsGranted('PLAN_WAREHOUSE_VIEW')]` і т. ін. — через PlanAccess, щоб не
 * ламати ієрархію ROLE_SUPPLY_* стендів заявок.
 *
 * @extends Voter<string, mixed>
 */
final class PlanAccessVoter extends Voter
{
    public function __construct(private readonly PlanAccess $access)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return PlanCapability::fromAttribute($attribute) !== null;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $capability = PlanCapability::fromAttribute($attribute);

        return $user instanceof TelegramUser && $capability !== null && $this->access->can($user, $capability);
    }
}
