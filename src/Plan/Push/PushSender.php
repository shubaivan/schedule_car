<?php

namespace App\Plan\Push;

use App\Entity\TelegramUser;
use App\Plan\Push\Entity\PushSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Web Push (VAPID): шле зашифроване сповіщення на всі пристрої людини.
 * Ключі — PLAN_VAPID_PUBLIC_KEY / PLAN_VAPID_PRIVATE_KEY (команда
 * `plan:push:vapid-keys`); порожні — push вимкнено, нічого не шлеться.
 * Push-сервіс сказав 404/410 — підписка мертва, видаляємо.
 */
final class PushSender
{
    /** Скільки збоїв поспіль терпимо, перш ніж забути підписку. */
    private const int MAX_FAILURES = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly ClientInterface $http,
        #[Autowire('%env(default::PLAN_VAPID_PUBLIC_KEY)%')]
        private readonly ?string $publicKey = null,
        #[Autowire('%env(default::PLAN_VAPID_PRIVATE_KEY)%')]
        private readonly ?string $privateKey = null,
        #[Autowire('%env(APP_BASE_URL)%')]
        private readonly string $subject = 'http://localhost',
    ) {
    }

    public function enabled(): bool
    {
        return trim((string) $this->publicKey) !== '' && trim((string) $this->privateKey) !== '';
    }

    public function publicKey(): ?string
    {
        return $this->enabled() ? trim((string) $this->publicKey) : null;
    }

    /** Зберегти (або перевісити на цю людину) підписку браузера. */
    public function subscribe(TelegramUser $user, string $endpoint, string $p256dh, string $auth, ?string $ua): PushSubscription
    {
        $subscription = $this->em->getRepository(PushSubscription::class)->findOneBy(['endpoint' => $endpoint]);

        if ($subscription === null) {
            $subscription = new PushSubscription($user, $endpoint, $p256dh, $auth, $ua);
            $this->em->persist($subscription);
        } else {
            $subscription->reassign($user, $p256dh, $auth, $ua);
        }

        $this->em->flush();

        return $subscription;
    }

    public function unsubscribe(TelegramUser $user, string $endpoint): void
    {
        $subscription = $this->em->getRepository(PushSubscription::class)->findOneBy(['endpoint' => $endpoint, 'user' => $user]);

        if ($subscription !== null) {
            $this->em->remove($subscription);
            $this->em->flush();
        }
    }

    /**
     * @param array{title: string, body: string, url: string, tag?: string, important?: bool} $message
     *
     * @return int на скільки пристроїв доставлено
     */
    public function send(int $userId, array $message): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $subscriptions = $this->em->getRepository(PushSubscription::class)->findBy(['user' => $userId]);

        if ($subscriptions === []) {
            return 0;
        }

        try {
            $push = new WebPush(
                ['VAPID' => ['subject' => $this->subject, 'publicKey' => trim((string) $this->publicKey), 'privateKey' => trim((string) $this->privateKey)]],
                ['TTL' => 3600, 'urgency' => ($message['important'] ?? false) ? 'high' : 'normal'],
                $this->http,
            );
        } catch (Throwable $e) {
            $this->logger->error('plan: Web Push не налаштовано', ['error' => $e->getMessage()]);

            return 0;
        }

        $byEndpoint = [];

        foreach ($subscriptions as $subscription) {
            $byEndpoint[$subscription->getEndpoint()] = $subscription;
            $push->queueNotification(
                new Subscription($subscription->getEndpoint(), $subscription->getP256dh(), $subscription->getAuth(), 'aes128gcm'),
                (string) json_encode($message, JSON_UNESCAPED_UNICODE),
            );
        }

        $delivered = 0;

        try {
            foreach ($push->flush() as $report) {
                $subscription = $byEndpoint[$report->getEndpoint()] ?? null;

                if ($subscription === null) {
                    continue;
                }

                if ($report->isSuccess()) {
                    $subscription->delivered();
                    ++$delivered;
                } elseif ($report->isSubscriptionExpired() || $subscription->getFailedCount() + 1 >= self::MAX_FAILURES) {
                    $this->em->remove($subscription);
                } else {
                    $subscription->failed();
                    $this->logger->info('plan: push не доставлено', ['user' => $userId, 'reason' => $report->getReason()]);
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('plan: push-сервіс недоступний', ['user' => $userId, 'error' => $e->getMessage()]);
        }

        $this->em->flush();

        return $delivered;
    }
}
