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

        return $this->switches[$channel->value . ':' . $group->value] ?? true;
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
                $matrix[$group->value][$channel->value] = $this->switches[$channel->value . ':' . $group->value] ?? true;
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
