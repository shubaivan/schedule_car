<?php

namespace App\Plan\Counterparty\Service;

use RuntimeException;

/**
 * Помилка, яку людина може виправити у формі. field — яке поле підсвітити,
 * existingId — уже наявна картка (дубль за кодом чи назвою), щоб дати посилання.
 */
final class CounterpartyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $field = null,
        public readonly ?int $existingId = null,
        public readonly bool $forbidden = false,
        /** Картку змінили паралельно (стара форма) або код щойно зайняли — 409. */
        public readonly bool $conflict = false,
    ) {
        parent::__construct($message);
    }
}
