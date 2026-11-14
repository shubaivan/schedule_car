<?php

namespace App\Plan\Subject;

use App\Entity\TelegramUser;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Одна точка доступу до SubjectProvider усіх модулів. */
final class SubjectRegistry
{
    /** @param iterable<SubjectProvider> $providers */
    public function __construct(
        #[AutowireIterator('plan.subject_provider')]
        private iterable $providers,
    ) {
    }

    public function provider(SubjectType $type): SubjectProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($type)) {
                return $provider;
            }
        }

        throw new LogicException(sprintf('No subject provider for "%s"', $type->value));
    }

    public function has(SubjectType $type): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($type)) {
                return true;
            }
        }

        return false;
    }

    public function title(SubjectRef $ref): ?string
    {
        return $this->provider($ref->type)->title($ref);
    }

    public function url(SubjectRef $ref): string
    {
        return $this->provider($ref->type)->url($ref);
    }

    /** @return list<int> */
    public function audience(SubjectRef $ref): array
    {
        return $this->provider($ref->type)->audience($ref);
    }

    public function visibleTo(SubjectRef $ref, TelegramUser $user): bool
    {
        return $this->provider($ref->type)->visibleTo($ref, $user);
    }
}
