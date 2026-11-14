<?php

namespace App\Plan\Subject;

use InvalidArgumentException;

/**
 * Посилання на об'єкт будь-якого модуля: тип + id. Спільна мова журналу подій,
 * Хроніки, пошуку й сповіщень — щоб жоден із них не знав про сутності модулів.
 *
 * Ключ `task-12` / `supply-40` / `wh_item-7` — той самий, що в маршрутах фронту
 * (`/chronicle/:key`, `/search/:key`).
 */
final readonly class SubjectRef
{
    public function __construct(
        public SubjectType $type,
        public int $id,
    ) {
        // 0 — лише «невідома людина» (невдалий вхід з чужим логіном).
        if ($id < 1 && ! ($id === 0 && $type === SubjectType::User)) {
            throw new InvalidArgumentException('Subject id must be positive');
        }
    }

    public static function of(SubjectType|string $type, int $id): self
    {
        return new self($type instanceof SubjectType ? $type : SubjectType::from($type), $id);
    }

    public static function task(int $id): self
    {
        return new self(SubjectType::Task, $id);
    }

    public static function supply(int $id): self
    {
        return new self(SubjectType::Supply, $id);
    }

    public static function user(int $id): self
    {
        return new self(SubjectType::User, $id);
    }

    /** Невдалий вхід, коли людину за логіном не знайдено. */
    public static function unknownUser(): self
    {
        return new self(SubjectType::User, 0);
    }

    /** `task-12` → SubjectRef(task, 12). */
    public static function fromKey(string $key): self
    {
        if (preg_match('/^([a-z_]{1,16})-(\d{1,10})$/', $key, $m) !== 1) {
            throw new InvalidArgumentException(sprintf('Bad subject key "%s"', $key));
        }

        $type = SubjectType::tryFrom($m[1]) ?? throw new InvalidArgumentException(sprintf('Unknown subject type "%s"', $m[1]));

        return new self($type, (int) $m[2]);
    }

    public function key(): string
    {
        return $this->type->value . '-' . $this->id;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    /** @return array{type: string, id: int} у такому вигляді суб'єкт іде у JSON для фронту */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'id' => $this->id];
    }
}
