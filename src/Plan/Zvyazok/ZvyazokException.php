<?php

namespace App\Plan\Zvyazok;

use RuntimeException;

/** Відмова «Звʼязку»: текст — для людини, код — HTTP-статус (404 — чату для вас немає). */
final class ZvyazokException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
