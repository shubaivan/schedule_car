<?php

namespace App\Plan\Notification;

use App\Entity\TelegramUser;
use App\Plan\Event\DriveAuthBroken;
use App\Plan\EventStore\PlanEventStore;
use App\Plan\Subject\SubjectRegistry;
use App\Plan\Subject\SubjectType;
use App\Repository\TelegramUserRepository;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Читання центру сповіщень: сторінка, лічильник, «нові після події N» для
 * опитування, позначки «прочитано». Текст і назву суб'єкта збираємо тут, а
 * видимість перевіряємо ще раз на читанні: права могли змінитись після події.
 */
final class NotificationInbox
{
    public function __construct(
        private readonly Connection $db,
        private readonly SubjectRegistry $subjects,
        private readonly TelegramUserRepository $users,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, next: ?int}
     */
    public function page(TelegramUser $user, bool $unreadOnly, ?int $before, int $limit): array
    {
        $sql = 'SELECT n.id AS n_id, n.event_group AS n_group, n.created_at AS n_created, n.read_at AS n_read, e.*
                FROM notification n JOIN plan_event e ON e.id = n.event_id
                WHERE n.user_id = ?';
        $params = [(int) $user->getId()];

        if ($unreadOnly) {
            $sql .= ' AND n.read_at IS NULL';
        }

        if ($before !== null) {
            $sql .= ' AND n.id < ?';
            $params[] = $before;
        }

        $rows = $this->db->fetchAllAssociative($sql . ' ORDER BY n.id DESC LIMIT ' . ($limit + 1), $params);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $items = $this->present($user, $rows);

        return ['items' => $items, 'next' => $more && $rows !== [] ? (int) end($rows)['n_id'] : null];
    }

    public function unread(TelegramUser $user): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM notification WHERE user_id = ? AND read_at IS NULL', [(int) $user->getId()]);
    }

    /** Остання подія, про яку людина вже має сповіщення, — звідки опитуванню й тосту рахувати «нове». */
    public function lastEvent(TelegramUser $user): int
    {
        return (int) $this->db->fetchOne('SELECT COALESCE(MAX(event_id), 0) FROM notification WHERE user_id = ?', [(int) $user->getId()]);
    }

    /**
     * Те саме, що приходить у Mercure, — для опитування, коли хаба немає.
     *
     * @return list<array{event: int, type: string, subject: array{type: string, id: int}, unread: int, important: bool}>
     */
    public function eventsAfter(TelegramUser $user, int $afterEvent, int $limit = 50): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT n.event_id, n.event_group, e.type, e.subject_type, e.subject_id FROM notification n JOIN plan_event e ON e.id = n.event_id
             WHERE n.user_id = ? AND n.event_id > ? ORDER BY n.event_id LIMIT ' . max(1, $limit),
            [(int) $user->getId(), $afterEvent],
        );
        $unread = $this->unread($user);

        return array_map(static fn (array $row) => [
            'event' => (int) $row['event_id'],
            'type' => (string) $row['type'],
            'subject' => ['type' => (string) $row['subject_type'], 'id' => (int) $row['subject_id']],
            'unread' => $unread,
            'important' => EventGroup::tryFrom((string) $row['event_group'])?->important() ?? false,
        ], $rows);
    }

    /** Одне сповіщення — для тосту (його текст) і після «прочитано». */
    public function one(TelegramUser $user, int $eventId): ?array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT n.id AS n_id, n.event_group AS n_group, n.created_at AS n_created, n.read_at AS n_read, e.*
             FROM notification n JOIN plan_event e ON e.id = n.event_id WHERE n.user_id = ? AND n.event_id = ?',
            [(int) $user->getId(), $eventId],
        );

        return $this->present($user, $rows)[0] ?? null;
    }

    public function markRead(TelegramUser $user, int $id): bool
    {
        return $this->db->executeStatement(
            'UPDATE notification SET read_at = LOCALTIMESTAMP(0) WHERE id = ? AND user_id = ? AND read_at IS NULL',
            [$id, (int) $user->getId()],
        ) > 0;
    }

    /** Відкрив задачу/заявку — сповіщення про неї прочитані. */
    public function markSubjectRead(TelegramUser $user, string $subjectType, int $subjectId): int
    {
        return $this->db->executeStatement(
            'UPDATE notification n SET read_at = LOCALTIMESTAMP(0) FROM plan_event e
             WHERE e.id = n.event_id AND n.user_id = ? AND n.read_at IS NULL AND e.subject_type = ? AND e.subject_id = ?',
            [(int) $user->getId(), $subjectType, $subjectId],
        );
    }

    public function markAllRead(TelegramUser $user): int
    {
        return $this->db->executeStatement('UPDATE notification SET read_at = LOCALTIMESTAMP(0) WHERE user_id = ? AND read_at IS NULL', [(int) $user->getId()]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function present(TelegramUser $user, array $rows): array
    {
        $actorIds = array_values(array_unique(array_filter(array_map(static fn (array $r) => $r['actor_id'] !== null ? (int) $r['actor_id'] : null, $rows))));
        $actors = [];

        foreach ($actorIds === [] ? [] : $this->users->findBy(['id' => $actorIds]) as $actor) {
            $actors[(int) $actor->getId()] = $actor;
        }

        $out = [];
        $kyiv = new DateTimeZone('Europe/Kyiv');
        $summaries = $this->warehouseSummaries($rows);

        foreach ($rows as $row) {
            try {
                $event = PlanEventStore::hydrate($row);
            } catch (Throwable) {
                continue;
            }

            $ref = $event->subject;
            // Службове «Диск відкликав доступ» веде на сторінку Диска, а не до суб'єкта файлу.
            $known = ! $event instanceof DriveAuthBroken && $this->subjects->has($ref->type);

            if ($known && ! $this->subjects->visibleTo($ref, $user)) {
                continue;
            }

            $link = NotificationLink::of($event, $this->subjects);

            $group = EventGroup::tryFrom((string) $row['n_group']) ?? EventGroup::forEvent($event) ?? EventGroup::Status;
            $actor = $event->actorId !== null ? ($actors[$event->actorId] ?? null) : null;
            $text = NotificationText::of($event, $group, $actor?->shortName(), $summaries[$ref->key()] ?? null);

            $out[] = [
                'id' => (int) $row['n_id'],
                'event' => (int) $event->id(),
                'type' => $event::type(),
                'group' => $group->value,
                'important' => $group->important(),
                'subject' => ['type' => $ref->type->value, 'id' => $ref->id, 'key' => $ref->key()],
                'title' => $link['title'],
                'url' => $link['url'],
                'headline' => $text['headline'],
                'detail' => $text['detail'],
                'actor' => $actor !== null ? ['id' => (int) $actor->getId(), 'name' => $actor->shortName()] : null,
                'createdAt' => new DateTimeImmutable((string) $row['n_created'], $kyiv)->format(DateTimeInterface::ATOM),
                'readAt' => $row['n_read'] !== null ? new DateTimeImmutable((string) $row['n_read'], $kyiv)->format(DateTimeInterface::ATOM) : null,
            ];
        }

        return $out;
    }

    /**
     * Суть складських подій для рядка сповіщення: «ПК 60-12-8 × 12 шт (+2) → ЖК «Лісовий»»
     * для руху, «🧱 Категорія» для нової позиції. Один запит на сторінку.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, string> ключ суб'єкта → текст
     */
    private function warehouseSummaries(array $rows): array
    {
        $movements = [];
        $items = [];

        foreach ($rows as $row) {
            $type = (string) ($row['subject_type'] ?? '');
            $id = (int) ($row['subject_id'] ?? 0);

            if ($type === SubjectType::WhMovement->value) {
                $movements[$id] = $id;
            } elseif ($type === SubjectType::WhItem->value) {
                $items[$id] = $id;
            }
        }

        $out = [];

        if ($movements !== []) {
            foreach ($this->db->fetchAllAssociative(
                "SELECT m.id, COALESCE(ts.name, fs.name) AS site, m.to_site_id IS NOT NULL AS towards,
                        (SELECT i.name || ' × ' || l.quantity || ' ' || COALESCE(NULLIF(i.unit, ''), 'шт')
                           FROM wh_movement_line l JOIN wh_item i ON i.id = l.item_id WHERE l.movement_id = m.id ORDER BY l.id LIMIT 1) AS first,
                        (SELECT COUNT(*) FROM wh_movement_line l WHERE l.movement_id = m.id) AS lines
                   FROM wh_movement m
                   LEFT JOIN wh_site ts ON ts.id = m.to_site_id
                   LEFT JOIN wh_site fs ON fs.id = m.from_site_id
                  WHERE m.id IN (?)",
                [array_values($movements)],
                [ArrayParameterType::INTEGER],
            ) as $m) {
                $text = (string) ($m['first'] ?? '');
                $more = (int) $m['lines'] - 1;
                $text .= $more > 0 ? ' (+' . $more . ')' : '';

                if ($m['site'] !== null) {
                    $text .= ($m['towards'] ? ' → ' : ' ← ') . $m['site'];
                }

                $out[SubjectType::WhMovement->value . '-' . $m['id']] = trim($text);
            }
        }

        if ($items !== []) {
            foreach ($this->db->fetchAllAssociative(
                'SELECT i.id, i.inventory_number, c.emoji, c.name FROM wh_item i LEFT JOIN wh_category c ON c.id = i.category_id WHERE i.id IN (?)',
                [array_values($items)],
                [ArrayParameterType::INTEGER],
            ) as $i) {
                $out[SubjectType::WhItem->value . '-' . $i['id']] = trim($i['inventory_number'] . ' · ' . trim(($i['emoji'] ?? '') . ' ' . ($i['name'] ?? '')), ' ·');
            }
        }

        return $out;
    }
}
