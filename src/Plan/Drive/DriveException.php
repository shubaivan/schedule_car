<?php

namespace App\Plan\Drive;

use RuntimeException;
use Throwable;

/**
 * Збій Диска. Вид визначає реакцію DriveSync:
 * - auth (invalid_grant, 401) — токен відкликано: зупинити все, одна подія суперадмінам;
 * - quota (403 квота / 429) — пауза на годину, спроба файлу не рахується;
 * - transient — усе інше: спроба файлу +1, backoff 2^n хв, після 6-ї — failed.
 */
final class DriveException extends RuntimeException
{
    public const string AUTH = 'auth';
    public const string QUOTA = 'quota';
    public const string TRANSIENT = 'transient';

    public function __construct(public readonly string $kind, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function auth(string $message, ?Throwable $previous = null): self
    {
        return new self(self::AUTH, $message, $previous);
    }

    public static function quota(string $message, ?Throwable $previous = null): self
    {
        return new self(self::QUOTA, $message, $previous);
    }

    public static function transient(string $message, ?Throwable $previous = null): self
    {
        return new self(self::TRANSIENT, $message, $previous);
    }
}
