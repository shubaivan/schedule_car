<?php

namespace App\Plan\Notification\Controller;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanAccess;
use App\Plan\Access\PlanCapability;
use App\Plan\Notification\Channel;
use App\Plan\Notification\EventGroup;
use App\Plan\Notification\NotificationInbox;
use App\Plan\Notification\NotificationPreferences;
use App\Plan\Push\PushSender;
use App\Plan\Realtime\MercurePublisher;
use App\Plan\Subject\SubjectType;
use App\Plan\Telegram\PlanBotMode;
use App\Plan\Telegram\TelegramLink;
use App\Tasks\Controller\TasksController;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Центр сповіщень «Плану»: сторінка, лічильник, позначки «прочитано»,
 * налаштування, живий канал (Mercure або опитування), Web Push, прив'язка Telegram.
 * Усе — лише про себе: чужих сповіщень через цей API не дістати.
 */
#[Route('/api/plan')]
final class NotificationApiController extends TasksController
{
    private const int PAGE = 30;

    public function __construct(
        private readonly NotificationInbox $inbox,
        private readonly NotificationPreferences $prefs,
        private readonly PlanAccess $access,
        private readonly MercurePublisher $mercure,
        private readonly PushSender $push,
        private readonly TelegramLink $telegram,
        private readonly PlanBotMode $planBot,
    ) {
    }

    /** ?unread=1 — лише непрочитані; ?before=<id> — наступна сторінка. */
    #[Route('/notifications', name: 'api_plan_notifications', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $me = $this->me();
        $before = $request->query->get('before');
        $page = $this->inbox->page($me, $request->query->getBoolean('unread'), is_numeric($before) ? (int) $before : null, min(100, max(5, $request->query->getInt('limit', self::PAGE))));

        return $this->json($page + ['unread' => $this->inbox->unread($me)]);
    }

    #[Route('/notifications/count', name: 'api_plan_notifications_count', methods: ['GET'])]
    public function count(): JsonResponse
    {
        $me = $this->me();

        return $this->json(['unread' => $this->inbox->unread($me), 'lastEvent' => $this->inbox->lastEvent($me)]);
    }

    /** Опитування (хаба немає): ті самі мінімальні події, що й у Mercure. */
    #[Route('/notifications/events', name: 'api_plan_notifications_events', methods: ['GET'])]
    public function events(Request $request): JsonResponse
    {
        $me = $this->me();

        return $this->json(['events' => $this->inbox->eventsAfter($me, max(0, $request->query->getInt('after')))]);
    }

    /** Одне сповіщення за id події — текст для тосту. */
    #[Route('/notifications/event/{event}', name: 'api_plan_notifications_one', requirements: ['event' => '\d+'], methods: ['GET'])]
    public function one(int $event): JsonResponse
    {
        $item = $this->inbox->one($this->me(), $event);

        return $item !== null ? $this->json($item) : $this->error('Сповіщення немає.', Response::HTTP_NOT_FOUND);
    }

    #[Route('/notifications/{id}/read', name: 'api_plan_notifications_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function read(int $id): JsonResponse
    {
        $me = $this->me();
        $this->inbox->markRead($me, $id);

        return $this->json(['unread' => $this->inbox->unread($me)]);
    }

    /** Відкрив задачу/заявку — усі сповіщення про неї прочитані. Тіло: {"subject": "task-12"}. */
    #[Route('/notifications/read-subject', name: 'api_plan_notifications_read_subject', methods: ['POST'])]
    public function readSubject(Request $request): JsonResponse
    {
        $me = $this->me();
        $key = (string) ($this->payload($request)['subject'] ?? '');

        if (preg_match('/^([a-z_]+)-(\d+)$/', $key, $m) !== 1 || SubjectType::tryFrom($m[1]) === null) {
            return $this->error('Невідомий суб\'єкт.', Response::HTTP_BAD_REQUEST);
        }

        $this->inbox->markSubjectRead($me, $m[1], (int) $m[2]);

        return $this->json(['unread' => $this->inbox->unread($me)]);
    }

    #[Route('/notifications/read-all', name: 'api_plan_notifications_read_all', methods: ['POST'])]
    public function readAll(): JsonResponse
    {
        $me = $this->me();
        $this->inbox->markAllRead($me);

        return $this->json(['unread' => 0]);
    }

    #[Route('/notifications/settings', name: 'api_plan_notifications_settings', methods: ['GET'])]
    public function settings(): JsonResponse
    {
        $me = $this->me();

        return $this->json($this->settingsView($me));
    }

    #[Route('/notifications/settings', name: 'api_plan_notifications_settings_save', methods: ['PUT', 'POST'])]
    public function saveSettings(Request $request): JsonResponse
    {
        $me = $this->me();
        $this->prefs->save($me, $this->payload($request), $this->access->can($me, PlanCapability::TasksSeeAll));

        return $this->json($this->settingsView($me));
    }

    /**
     * Живий канал: є хаб — кука mercureAuthorization (HttpOnly, лише свій топік,
     * 12 год) і адреса хаба; немає — фронт опитує /notifications/events.
     */
    #[Route('/realtime', name: 'api_plan_realtime', methods: ['GET'])]
    public function realtime(Request $request, Authorization $authorization): JsonResponse
    {
        $me = $this->me();
        $topic = $this->mercure->topic((int) $me->getId());
        $data = [
            'mode' => 'polling',
            'hub' => null,
            'topic' => $topic,
            'lastEvent' => $this->inbox->lastEvent($me),
            'unread' => $this->inbox->unread($me),
        ];
        $cookie = null;

        if ($this->mercure->enabled()) {
            try {
                $cookie = $authorization->createCookie($request, [new Grant([Grant::ACTION_SUBSCRIBE], [$topic])], null, ['exp' => new DateTimeImmutable('+12 hours')]);
                $data['mode'] = 'mercure';
                $data['hub'] = $this->mercure->publicUrl();
            } catch (Throwable) {
                // Хаб налаштовано криво — працюємо на опитуванні, а не падаємо.
            }
        }

        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'no-store');

        if ($cookie !== null) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    #[Route('/push/subscriptions', name: 'api_plan_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        $me = $this->me();

        if (! $this->push->enabled()) {
            return $this->error('Push-сповіщення на цьому сервері не налаштовано.', Response::HTTP_CONFLICT);
        }

        $data = $this->payload($request);
        $endpoint = (string) ($data['endpoint'] ?? '');
        $keys = is_array($data['keys'] ?? null) ? $data['keys'] : [];

        if (! str_starts_with($endpoint, 'https://') || strlen($endpoint) > 1000 || ! is_string($keys['p256dh'] ?? null) || ! is_string($keys['auth'] ?? null)) {
            return $this->error('Неповна підписка браузера.', Response::HTTP_BAD_REQUEST);
        }

        $this->push->subscribe($me, $endpoint, $keys['p256dh'], $keys['auth'], $request->headers->get('User-Agent'));

        return $this->json(['ok' => true], Response::HTTP_CREATED);
    }

    #[Route('/push/subscriptions', name: 'api_plan_push_unsubscribe', methods: ['DELETE'])]
    public function unsubscribe(Request $request): JsonResponse
    {
        $this->push->unsubscribe($this->me(), (string) ($this->payload($request)['endpoint'] ?? ''));

        return $this->json(['ok' => true]);
    }

    /** Перевірка з налаштувань: «надіслати тестове push на мої пристрої». */
    #[Route('/push/test', name: 'api_plan_push_test', methods: ['POST'])]
    public function pushTest(): JsonResponse
    {
        $me = $this->me();
        $sent = $this->push->send((int) $me->getId(), ['title' => 'План · перевірка', 'body' => 'Push-сповіщення працюють на цьому пристрої.', 'url' => '/crm/notifications/settings', 'tag' => 'plan-test']);

        return $this->json(['delivered' => $sent]);
    }

    /** Одноразове посилання на бот Плану (15 хв). */
    #[Route('/telegram/link', name: 'api_plan_telegram_link', methods: ['POST'])]
    public function telegramLink(): JsonResponse
    {
        $me = $this->me();

        if ($this->telegram->botUsername() === null) {
            return $this->error('Telegram-бот на цьому сервері ще не підключено.', Response::HTTP_CONFLICT);
        }

        $link = $this->telegram->issue($me);

        return $this->json(['url' => $link['url'], 'expiresAt' => $link['expiresAt']->format(DateTimeInterface::ATOM)]);
    }

    #[Route('/telegram/link', name: 'api_plan_telegram_unlink', methods: ['DELETE'])]
    public function telegramUnlink(): JsonResponse
    {
        $me = $this->me();
        $this->telegram->unlink($me);

        return $this->json($this->settingsView($me));
    }

    /** @return array<string, mixed> */
    private function settingsView(TelegramUser $me): array
    {
        $groups = [];

        foreach (EventGroup::cases() as $group) {
            $groups[] = ['id' => $group->value, 'label' => $group->label(), 'important' => $group->important()];
        }

        return [
            'prefs' => $this->prefs->of((int) $me->getId())->toArray(),
            'groups' => $groups,
            'channels' => array_map(static fn (Channel $c) => ['id' => $c->value, 'label' => $c->label()], Channel::cases()),
            'canWatchAll' => $this->access->can($me, PlanCapability::TasksSeeAll),
            'telegram' => [
                'bot' => $this->telegram->botUsername(),
                'linked' => TelegramLink::isLinked($me),
                'planBot' => $this->planBot->enabled(),
            ],
            'push' => ['publicKey' => $this->push->publicKey()],
        ];
    }
}
