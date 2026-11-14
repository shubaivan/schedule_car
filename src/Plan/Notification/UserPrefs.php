<?php

namespace App\Plan\Notification;

use App\Plan\Notification\Entity\NotificationSetting;
use DateTimeImmutable;
use DateTimeZone;

/** Налаштування сповіщень однієї людини (знімок; дефолти — коли рядків немає). */
final readonly class UserPrefs
{
    /**
     * @param array<string, bool> $switches "channel:group" → увімкнено (лише явно збережені)
     */
    public function __construct(
        public string $scope = NotificationSetting::SCOPE_MINE,
        public bool $telegram = true,
        public bool $push = true,
        public bool $sound = false,
        public ?int $quietFrom = null,
        public ?int $quietTo = null,
        public array $switches = [],
    ) {
    }

    public function allows(Channel $channel, EventGroup $group): bool
    {
        if (($channel === Channel::Telegram && ! $this->telegram) || ($channel === Channel::Push && ! $this->push)) {
            return false;
        }

        return $this->switches[$channel->value . ':' . $group->value] ?? self::byDefault($channel, $group, $this->scope);
    }

    /**
     * Типово (людина нічого не міняла):
     * - дзвіночок «У Плані» (і push, що йде лише за ним) — тільки те, що потребує саме вас:
     *   згадки, вас додали / нова задача на вас, потрібне рішення, поділились файлом,
     *   лід, службове. Коментарі, статуси й закупівлі видно в Хроніці з «+N»;
     *   хто обрав «Усе» (директор/адмін), отримує в дзвіночок і їх;
     * - склад (рухи, нові позиції) — вимкнено скрізь: увімкнути можна в налаштуваннях;
     * - Telegram — як і раніше, усе, крім складу.
     */
    public static function byDefault(Channel $channel, EventGroup $group, string $scope = NotificationSetting::SCOPE_MINE): bool
    {
        if ($group === EventGroup::Warehouse) {
            return false;
        }

        if ($channel === Channel::Telegram) {
            return true;
        }

        return $group->needsMe() || $scope === NotificationSetting::SCOPE_ALL;
    }

    /**
     * Чи зараз тихі години (за Києвом). Інтервал через північ (22:00–07:00) теж працює.
     */
    public function quietAt(DateTimeImmutable $now): bool
    {
        if ($this->quietFrom === null || $this->quietTo === null || $this->quietFrom === $this->quietTo) {
            return false;
        }

        $local = $now->setTimezone(new DateTimeZone('Europe/Kyiv'));
        $minute = (int) $local->format('G') * 60 + (int) $local->format('i');

        return $this->quietFrom < $this->quietTo
            ? $minute >= $this->quietFrom && $minute < $this->quietTo
            : $minute >= $this->quietFrom || $minute < $this->quietTo;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $matrix = [];

        foreach (EventGroup::cases() as $group) {
            foreach (Channel::cases() as $channel) {
                $matrix[$group->value][$channel->value] = $this->switches[$channel->value . ':' . $group->value] ?? self::byDefault($channel, $group, $this->scope);
            }
        }

        return [
            'scope' => $this->scope,
            'telegram' => $this->telegram,
            'push' => $this->push,
            'sound' => $this->sound,
            'quietFrom' => self::hhmm($this->quietFrom),
            'quietTo' => self::hhmm($this->quietTo),
            'groups' => $matrix,
        ];
    }

    public static function hhmm(?int $minutes): ?string
    {
        return $minutes === null ? null : sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public static function minutes(mixed $hhmm): ?int
    {
        if (! is_string($hhmm) || preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $hhmm, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
