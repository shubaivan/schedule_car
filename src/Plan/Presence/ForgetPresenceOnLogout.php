<?php

namespace App\Plan\Presence;

use App\Entity\TelegramUser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/** «Вийти» — людина зникає з «зараз у Плані» одразу, а не через 3 хвилини. */
#[AsEventListener(event: LogoutEvent::class)]
final readonly class ForgetPresenceOnLogout
{
    public function __construct(private PresenceTracker $tracker)
    {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();

        if ($user instanceof TelegramUser && $user->getId() !== null) {
            $this->tracker->forget($user);
        }
    }
}
