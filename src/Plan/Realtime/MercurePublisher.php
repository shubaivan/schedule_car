<?php

namespace App\Plan\Realtime;

use App\Plan\Subject\SubjectRef;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

/**
 * Публікація живих подій у Mercure-хаб: лише ПЕРСОНАЛЬНІ приватні топіки
 * (`<APP_BASE_URL>/u/<id>`), payload мінімальний — {event, type, subject, unread,
 * important}. Жодних назв, текстів, імен: клієнт дочитує все через API, де діють
 * звичайні права. Хаба немає (MERCURE_URL порожній — стенди, тести) — no-op;
 * хаб упав — лог і далі (фронт працює на опитуванні).
 */
final class MercurePublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::MERCURE_URL)%')]
        private readonly ?string $hubUrl = null,
        #[Autowire('%env(APP_BASE_URL)%')]
        private readonly string $baseUrl = 'http://localhost',
    ) {
    }

    public function enabled(): bool
    {
        return $this->hubUrl !== null && trim($this->hubUrl) !== '';
    }

    /** Куди підключається браузер (той самий origin: `/.well-known/mercure` за nginx). */
    public function publicUrl(): string
    {
        return $this->hub->getPublicUrl();
    }

    public function topic(int $userId): string
    {
        return rtrim($this->baseUrl, '/') . '/u/' . $userId;
    }

    /**
     * Payload для людини: лише ідентифікатори й лічильник.
     *
     * @return array{event: int, type: string, subject: array{type: string, id: int}, unread: int, important: bool}
     */
    public static function payload(int $eventId, string $type, SubjectRef $subject, int $unread, bool $important): array
    {
        return [
            'event' => $eventId,
            'type' => $type,
            'subject' => ['type' => $subject->type->value, 'id' => $subject->id],
            'unread' => $unread,
            'important' => $important,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function publish(int $userId, array $payload): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            $this->hub->publish(new Update(
                $this->topic($userId),
                (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                private: true,
                id: sprintf('u%d-e%d', $userId, (int) ($payload['event'] ?? 0)),
            ));

            return true;
        } catch (Throwable $e) {
            $this->logger->warning('plan: Mercure-хаб не прийняв подію', ['user' => $userId, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
