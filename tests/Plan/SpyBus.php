<?php

namespace App\Tests\Plan;

use Closure;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** Шина для тестів: запам'ятовує повідомлення й (за бажання) передає далі. */
final class SpyBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    /** @param (Closure(object): mixed)|null $then */
    public function __construct(private readonly ?Closure $then = null)
    {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        if ($this->then !== null) {
            ($this->then)($message);
        }

        return Envelope::wrap($message, $stamps);
    }
}
