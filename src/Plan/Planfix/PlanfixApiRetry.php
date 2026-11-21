<?php

namespace App\Plan\Planfix;

use RuntimeException;

/** Відповідь Planfix не 200 — PlanfixApiClient вирішує за кодом, чи повторити. */
final class PlanfixApiRetry extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
