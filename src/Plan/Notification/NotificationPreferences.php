<?php

namespace App\Plan\Notification;

use App\Entity\TelegramUser;
use App\Plan\Notification\Entity\NotificationSetting;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Читає й зберігає налаштування сповіщень. Читання — сирим SQL і без кешу між
 * викликами: воркер живе годину, а людина могла щойно змінити налаштування.
 */
final class NotificationPreferences
{
    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function of(int $userId): UserPrefs
    {
        return $this->ofMany([$userId])[$userId];
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, UserPrefs>
     */
    public function ofMany(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($userIds === []) {
            return [];
        }

        $settings = [];

        foreach ($this->db->fetchAllAssociative('SELECT * FROM notification_setting WHERE user_id IN (?)', [$userIds], [ArrayParameterType::INTEGER]) as $row) {
            $settings[(int) $row['user_id']] = $row;
        }

        $switches = [];

        foreach ($this->db->fetchAllAssociative('SELECT user_id, channel, event_group, enabled FROM notification_pref WHERE user_id IN (?)', [$userIds], [ArrayParameterType::INTEGER]) as $row) {
            $switches[(int) $row['user_id']][$row['channel'] . ':' . $row['event_group']] = (bool) $row['enabled'];
        }

        $out = [];

        foreach ($userIds as $id) {
            $s = $settings[$id] ?? null;
            $out[$id] = $s === null
                ? new UserPrefs(switches: $switches[$id] ?? [])
                : new UserPrefs(
                    scope: (string) $s['scope'],
                    telegram: (bool) $s['telegram'],
                    push: (bool) $s['push'],
                    sound: (bool) $s['sound'],
                    quietFrom: $s['quiet_from'] !== null ? (int) $s['quiet_from'] : null,
                    quietTo: $s['quiet_to'] !== null ? (int) $s['quiet_to'] : null,
                    switches: $switches[$id] ?? [],
                );
        }

        return $out;
    }

    /**
     * Зберегти те, що прийшло зі сторінки налаштувань (часткове оновлення:
     * відсутній ключ не змінюється). «Усе» доступне лише тим, хто бачить усі задачі.
     *
     * @param array<string, mixed> $input
     */
    public function save(TelegramUser $user, array $input, bool $mayWatchAll): UserPrefs
    {
        $setting = $this->setting($user);

        if (array_key_exists('scope', $input)) {
            $setting->setScope($mayWatchAll && $input['scope'] === NotificationSetting::SCOPE_ALL ? NotificationSetting::SCOPE_ALL : NotificationSetting::SCOPE_MINE);
        }

        foreach (['telegram' => 'setTelegram', 'push' => 'setPush', 'sound' => 'setSound'] as $key => $setter) {
            if (array_key_exists($key, $input)) {
                $setting->{$setter}((bool) $input[$key]);
            }
        }

        if (array_key_exists('quietFrom', $input) || array_key_exists('quietTo', $input)) {
            // Частковий запит (лише «з» або лише «до») решту бере з уже збереженого.
            $from = array_key_exists('quietFrom', $input) ? UserPrefs::minutes($input['quietFrom']) : $setting->getQuietFrom();
            $to = array_key_exists('quietTo', $input) ? UserPrefs::minutes($input['quietTo']) : $setting->getQuietTo();
            $setting->setQuietHours($from, $to);
        }

        if (isset($input['groups']) && is_array($input['groups'])) {
            foreach ($input['groups'] as $group => $channels) {
                $group = EventGroup::tryFrom((string) $group);

                if ($group === null || ! is_array($channels)) {
                    continue;
                }

                foreach ($channels as $channel => $enabled) {
                    $channel = Channel::tryFrom((string) $channel);

                    if ($channel !== null) {
                        $this->db->executeStatement(
                            'INSERT INTO notification_pref (user_id, channel, event_group, enabled) VALUES (?, ?, ?, ?)
                             ON CONFLICT (user_id, channel, event_group) DO UPDATE SET enabled = EXCLUDED.enabled',
                            [(int) $user->getId(), $channel->value, $group->value, (bool) $enabled],
                            [3 => ParameterType::BOOLEAN],
                        );
                    }
                }
            }
        }

        $this->em->flush();

        return $this->of((int) $user->getId());
    }

    /** /mute і /unmute у боті Плану, «Telegram» у профілі. */
    public function setTelegram(TelegramUser $user, bool $on): void
    {
        $this->setting($user)->setTelegram($on);
        $this->em->flush();
    }

    private function setting(TelegramUser $user): NotificationSetting
    {
        $setting = $this->em->find(NotificationSetting::class, $user->getId());

        if ($setting === null) {
            $setting = new NotificationSetting($user);
            $this->em->persist($setting);
        }

        return $setting;
    }
}
