<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Доменна подія «Плану»: що сталося, з чим (SubjectRef), хто (actorId) і коли.
 *
 * Кожен клас-нащадок — один тип події (`TYPE`, пишеться в plan_event.type).
 * Корисне навантаження — ВЛАСНІ параметри конструктора нащадка (крім subject,
 * actorId, at): лише скаляри, null і масиви скалярів — так подія без втрат
 * переживає JSON (plan_event.payload) і відновлюється у воркері тим самим класом.
 *
 * Записує подію лише PlanEventRecorder (рядок plan_event у тій самій транзакції,
 * що й зміна), читають — споживачі PlanEventConsumer.
 */
abstract class PlanEvent
{
    /** Тип у журналі; перевизначає кожен нащадок. */
    public const string TYPE = '';

    /** Параметри конструктора, які не є корисним навантаженням. */
    private const array ENVELOPE = ['subject', 'actorId', 'at'];

    public readonly DateTimeImmutable $at;

    private ?int $id = null;
    private ?string $channel = null;
    private ?string $ip = null;

    public function __construct(
        public readonly SubjectRef $subject,
        public readonly ?int $actorId,
        ?DateTimeImmutable $at = null,
    ) {
        // Як усі дати в БД — київський настінний час (див. Kernel::boot).
        $this->at = $at ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'));
    }

    public static function type(): string
    {
        if (static::TYPE === '') {
            throw new LogicException(static::class . ' must define TYPE');
        }

        return static::TYPE;
    }

    /** id рядка plan_event; null — подію ще не записано. */
    public function id(): ?int
    {
        return $this->id;
    }

    /** web | bot | cli | api — звідки прийшла дія. */
    public function channel(): ?string
    {
        return $this->channel;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    /**
     * Явно вказати канал (інакше його визначає PlanEventRecorder за запитом).
     */
    public function via(string $channel, ?string $ip = null): static
    {
        $copy = clone $this;
        $copy->channel = $channel;
        $copy->ip = $ip;

        return $copy;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $out = [];

        foreach (self::payloadParameters() as $name) {
            $out[$name] = $this->{$name};
        }

        return $out;
    }

    /**
     * Відновити подію з рядка журналу. Невідомі ключі payload ігноруються,
     * відсутні беруть значення за замовчуванням конструктора — так старі рядки
     * читаються й після того, як подія отримала нове необов'язкове поле.
     *
     * @param array<string, mixed> $payload
     */
    public static function restore(
        int $id,
        SubjectRef $subject,
        ?int $actorId,
        DateTimeImmutable $at,
        array $payload,
        ?string $channel = null,
        ?string $ip = null,
    ): static {
        $args = ['subject' => $subject, 'actorId' => $actorId, 'at' => $at];

        foreach (self::payloadParameters() as $name) {
            if (array_key_exists($name, $payload)) {
                $args[$name] = $payload[$name];
            }
        }

        /** @phpstan-ignore new.static */
        $event = new static(...$args);
        $event->id = $id;
        $event->channel = $channel;
        $event->ip = $ip;

        return $event;
    }

    /** @internal для PlanEventRecorder */
    public function withId(int $id, string $channel, ?string $ip): static
    {
        $copy = clone $this;
        $copy->id = $id;
        $copy->channel = $channel;
        $copy->ip = $ip;

        return $copy;
    }

    /** @return list<string> */
    private static function payloadParameters(): array
    {
        static $cache = [];

        if (isset($cache[static::class])) {
            return $cache[static::class];
        }

        $names = [];
        $constructor = (new ReflectionClass(static::class))->getConstructor();

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if (in_array($parameter->getName(), self::ENVELOPE, true)) {
                continue;
            }

            $type = $parameter->getType();

            if (! $parameter->isPromoted() || ! $type instanceof ReflectionNamedType || ! in_array($type->getName(), ['int', 'string', 'bool', 'float', 'array'], true)) {
                throw new LogicException(sprintf('%s::$%s must be a promoted int|string|bool|float|array parameter', static::class, $parameter->getName()));
            }

            $names[] = $parameter->getName();
        }

        return $cache[static::class] = $names;
    }
}
