<?php

namespace App\Plan\Subject\Provider;

use App\Entity\TelegramUser;
use App\Plan\Subject\SubjectProvider;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Людина як суб'єкт — для подій входу (UserLoggedIn, LoginFailed). */
final class UserSubjects implements SubjectProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function supports(SubjectType $type): bool
    {
        return $type === SubjectType::User;
    }

    public function title(SubjectRef $ref): ?string
    {
        return $this->em->find(TelegramUser::class, $ref->id)?->shortName();
    }

    public function url(SubjectRef $ref): string
    {
        return $this->urls->generate('crm_index', ['path' => 'staff']);
    }

    public function audience(SubjectRef $ref): array
    {
        return [$ref->id];
    }

    public function visibleTo(SubjectRef $ref, TelegramUser $user): bool
    {
        return $user->getId() === $ref->id || $user->getWorkRole()->managesStaff() || $user->isSuperadmin();
    }
}
