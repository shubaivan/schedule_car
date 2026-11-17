<?php

namespace App\Plan\Audit;

use App\Plan\Access\RolePermission;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectRegistry;
use App\Plan\Subject\SubjectType;
use App\Supply\Enum\SupplyStatus;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Enum\WorkRole;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\MovementType;
use Throwable;

/**
 * Рядок plan_event → те, що бачить суперадмін: хто, коли, що (людською мовою),
 * з чим (тип, назва й адреса суб'єкта), канал, IP.
 *
 * Статус і коментар до нього: живий журнал пише одну подію (коментар у payload),
 * перенесена історія задач — дві (статус + окремий «Коментар»). Тут обидва
 * варіанти показуються однаково: рядок статусу несе поле `comment`, а сторінка
 * малює його тим самим блоком «Коментар», що й окрему подію, — тож у списку
 * завжди видно і перехід, і текст.
 *
 * Назви суб'єктів кешуються на час запиту (CSV на 50k рядків — не 50k запитів).
 *
 * @phpstan-import-type AuditRow from AuditLog
 */
final class AuditPresenter
{
    /** @var array<string, ?string> */
    private array $titles = [];

    public function __construct(
        private readonly SubjectRegistry $subjects,
        private readonly AuditLog $log,
    ) {
    }

    /**
     * @param list<AuditRow> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $rows): array
    {
        $names = $this->names($rows);

        return array_map(fn (array $row) => $this->row($row, $names), $rows);
    }

    /**
     * Повна картка події: рядок + сирий payload + люди, згадані в payload.
     *
     * @param AuditRow $row
     * @param list<AuditRow> $around
     *
     * @return array<string, mixed>
     */
    public function detail(array $row, array $around): array
    {
        $payload = self::payload($row);
        $mentioned = [];

        foreach (['userIds', 'removedIds', 'mentions'] as $key) {
            foreach ((array) ($payload[$key] ?? []) as $id) {
                $mentioned[] = (int) $id;
            }
        }

        $names = $this->names([$row, ...$around], $mentioned);

        return [
            ...$this->row($row, $names),
            'payload' => $payload,
            'people' => array_values(array_map(
                static fn (int $id) => ['id' => $id, 'name' => $names[$id] ?? ('#' . $id)],
                array_values(array_unique($mentioned)),
            )),
            'around' => array_map(fn (array $r) => $this->row($r, $names), $around),
        ];
    }

    /**
     * @param AuditRow $row
     * @param array<int, string> $names
     *
     * @return array<string, mixed>
     */
    public function row(array $row, array $names = []): array
    {
        $payload = self::payload($row);
        $type = $row['type'];

        return [
            'id' => $row['id'],
            'type' => $type,
            'typeLabel' => AuditCatalog::label($type),
            'group' => AuditCatalog::groupOf($type),
            'at' => $row['occurred_at'],
            'actor' => $row['actor_id'] !== null ? ['id' => $row['actor_id'], 'name' => $names[$row['actor_id']] ?? ('#' . $row['actor_id'])] : null,
            'subject' => $this->subject($row),
            'summary' => $this->summary($type, $row['subject_type'], $payload),
            'comment' => self::comment($type, $payload),
            'channel' => $row['channel'],
            'ip' => $row['ip'],
            'userAgent' => isset($payload['userAgent']) ? (string) $payload['userAgent'] : null,
            'backfill' => ($payload['backfill'] ?? false) === true,
        ];
    }

    /**
     * Рядок CSV (без payload): ті самі поля, що й у таблиці.
     *
     * @param AuditRow $row
     * @param array<int, string> $names
     *
     * @return list<string>
     */
    public function csv(array $row, array $names): array
    {
        $item = $this->row($row, $names);
        /** @var array{key: string, kindLabel: string, title: ?string, url: ?string} $subject */
        $subject = $item['subject'];
        /** @var array{id: int, name: string}|null $actor */
        $actor = $item['actor'];

        return [
            (string) $item['id'],
            (string) $item['at'],
            (string) $item['typeLabel'],
            (string) $item['type'],
            $actor['name'] ?? '',
            $subject['kindLabel'],
            $subject['key'],
            (string) $subject['title'],
            (string) $item['summary'],
            (string) $item['comment'],
            (string) $item['channel'],
            (string) $item['ip'],
            $item['backfill'] ? 'так' : '',
        ];
    }

    /** @return list<string> */
    public static function csvHeader(): array
    {
        return ['id', 'Час (Київ)', 'Подія', 'Тип', 'Хто', 'Що', 'Ключ', 'Назва', 'Деталі', 'Коментар', 'Канал', 'IP', 'Перенесено з історії'];
    }

    /**
     * Імена всіх, хто діяв у рядках (+ додаткові id) — одним запитом.
     *
     * @param list<AuditRow> $rows
     * @param list<int> $extra
     *
     * @return array<int, string>
     */
    public function names(array $rows, array $extra = []): array
    {
        $ids = $extra;

        foreach ($rows as $row) {
            if ($row['actor_id'] !== null) {
                $ids[] = $row['actor_id'];
            }

            if ($row['subject_type'] === SubjectType::User->value) {
                $ids[] = $row['subject_id'];
            }
        }

        return $this->log->names($ids);
    }

    /**
     * @param AuditRow $row
     *
     * @return array{type: string, id: int, key: string, kindLabel: string, title: ?string, url: ?string}
     */
    private function subject(array $row): array
    {
        $type = SubjectType::tryFrom($row['subject_type']);
        $key = $row['subject_type'] . '-' . $row['subject_id'];
        $title = null;
        $url = null;

        if ($type !== null && $row['subject_id'] > 0 && $this->subjects->has($type)) {
            $ref = new SubjectRef($type, $row['subject_id']);

            if (! array_key_exists($key, $this->titles)) {
                try {
                    $this->titles[$key] = $this->subjects->title($ref);
                } catch (Throwable) {
                    $this->titles[$key] = null;
                }
            }

            $title = $this->titles[$key];

            try {
                $url = $this->subjects->url($ref);
            } catch (Throwable) {
                $url = null;
            }
        }

        if ($type === SubjectType::User && $row['subject_id'] === 0) {
            $title = 'Невідомий логін';
        }

        return [
            'type' => $row['subject_type'],
            'id' => $row['subject_id'],
            'key' => $key,
            'kindLabel' => AuditCatalog::subjectLabel($row['subject_type']),
            'title' => $title,
            'url' => $url,
        ];
    }

    /** @param array<string, mixed> $p */
    private function summary(string $type, string $subjectType, array $p): string
    {
        $str = static fn (string $key): string => isset($p[$key]) && is_scalar($p[$key]) ? (string) $p[$key] : '';
        $list = static fn (string $key): array => array_values(array_map('strval', (array) ($p[$key] ?? [])));

        return match ($type) {
            'subject_created', 'item_created' => $str('title'),
            'subject_updated' => $str('summary') !== '' ? $str('summary') : ($list('fields') !== [] ? 'поля: ' . implode(', ', $list('fields')) : ''),
            'status_changed', 'approval_requested', 'subject_closed' => trim(($p['from'] ?? null) !== null ? self::status($subjectType, $str('from')) . ' → ' . self::status($subjectType, $str('to')) : self::status($subjectType, $str('to'))),
            'comment_added' => $str('excerpt') . ((int) ($p['files'] ?? 0) > 0 ? ' · файлів: ' . (int) $p['files'] : ''),
            'comment_edited' => $str('excerpt'),
            'comment_deleted' => 'повідомлення #' . $str('refId'),
            'mention_added' => 'згадано людей: ' . count($list('userIds')),
            'member_added' => 'додано: ' . count($list('userIds')) . (count($list('removedIds')) > 0 ? ', прибрано: ' . count($list('removedIds')) : ''),
            'member_removed' => 'прибрано: ' . count($list('userIds')),
            'file_added', 'file_removed' => implode(', ', $list('names')),
            'purchase_recorded' => trim($str('supplier') . ' · ' . $str('total'), ' ·') . (($p['updated'] ?? false) === true ? ' (виправлено)' : ''),
            'purchase_removed' => trim($str('supplier') . ' · ' . $str('total'), ' ·'),
            'stock_moved' => trim((MovementType::tryFrom($str('movementType'))?->label() ?? $str('movementType')) . ' · ' . $str('title'), ' ·'),
            'file_downloaded' => trim($str('name') . ' · ' . self::fileType($str('fileType')), ' ·'),
            'share_created' => trim('файл #' . $str('fileId') . ($str('via') !== '' ? ' → ' . $str('via') : '') . (count($list('userIds')) > 0 ? ', людей: ' . count($list('userIds')) : '')),
            'subject_viewed' => trim((ActivityAction::tryFrom($str('action'))?->label() ?? $str('action')) . ($str('details') !== '' ? ' · ' . $str('details') : '')),
            'user_logged_in' => match ($str('method')) {
                'password' => 'за паролем',
                'bot' => 'посиланням із бота',
                'telegram' => 'через Telegram',
                default => '',
            },
            'login_failed' => trim($str('name') . ($str('reason') !== '' ? ' · ' . self::reason($str('reason')) : ''), ' ·'),
            'staff_changed' => self::staff($str('action'), $list('fields'), $str('roleFrom'), $str('roleTo')),
            'role_permission_changed' => trim((WorkRole::tryFrom($str('role'))?->label() ?? $str('role')) . ' · ' . (RolePermission::tryFrom($str('permission'))?->label() ?? $str('permission')) . ' · ' . (($p['allowed'] ?? false) === true ? 'дозволено' : 'заборонено'), ' ·'),
            'join_requested' => trim('№' . $str('requestId') . ' · ' . $str('name') . ($str('username') !== '' ? ' (@' . $str('username') . ')' : '') . ' · ' . $str('phone'), ' ·'),
            'join_decided' => trim('№' . $str('requestId') . ' · ' . ($str('decision') === 'approved' ? 'затверджено' : 'відхилено') . ' · ' . $str('name') . ($str('role') !== '' ? ' · ' . (WorkRole::tryFrom($str('role'))?->label() ?? $str('role')) : '') . ($str('reason') !== '' ? ' · ' . $str('reason') : ''), ' ·'),
            'lead_received' => trim($str('name') . ' · ' . $str('interest'), ' ·'),
            default => '',
        };
    }

    /** @param array<string, mixed> $p */
    private static function comment(string $type, array $p): ?string
    {
        if (! in_array($type, ['status_changed', 'approval_requested', 'subject_closed'], true)) {
            return null;
        }

        $comment = isset($p['comment']) && is_string($p['comment']) ? trim($p['comment']) : '';

        return $comment !== '' ? $comment : null;
    }

    /** @param list<string> $fields */
    private static function staff(string $action, array $fields, string $roleFrom, string $roleTo): string
    {
        $labels = ['firstName' => "ім'я", 'lastName' => 'прізвище', 'position' => 'посада', 'email' => 'email', 'phone' => 'телефон', 'role' => 'роль', 'departmentId' => 'відділ', 'password' => 'пароль'];
        $role = static fn (string $value): string => WorkRole::tryFrom($value)?->label() ?? $value;
        $parts = [match ($action) {
            'create' => 'заведено',
            'telegram' => 'підключено Telegram за номером телефону',
            'trash' => 'у кошик',
            'restore' => 'з кошика',
            'superadmin_grant' => 'видано права суперадміністратора',
            'superadmin_revoke' => 'знято права суперадміністратора',
            default => 'змінено',
        }];

        if ($fields !== [] && $action !== 'trash' && $action !== 'restore') {
            $parts[] = implode(', ', array_map(static fn (string $f) => $labels[$f] ?? $f, $fields));
        }

        if ($roleTo !== '') {
            $parts[] = ($roleFrom !== '' ? $role($roleFrom) . ' → ' : '') . $role($roleTo);
        }

        return implode(' · ', $parts);
    }

    private static function status(string $subjectType, string $value): string
    {
        if ($value === '') {
            return '';
        }

        return match ($subjectType) {
            SubjectType::Task->value => TaskStatus::tryFrom($value)?->label() ?? $value,
            SubjectType::Supply->value => SupplyStatus::tryFrom($value)?->label() ?? $value,
            default => $value,
        };
    }

    /** Причини відмови Symfony (англійські ключі) — людською мовою; свої тексти CRM лишаються як є. */
    private static function reason(string $reason): string
    {
        return match (true) {
            $reason === 'Invalid credentials.' => 'невірний пароль',
            str_starts_with($reason, 'Too many failed login attempts') => 'забагато спроб, пауза',
            $reason === 'Invalid CSRF token.' => 'застаріла форма входу (CSRF)',
            $reason === 'Bad credentials.' => 'невірний логін або пароль',
            default => $reason,
        };
    }

    private static function fileType(string $type): string
    {
        return match ($type) {
            'task' => 'файл задачі',
            'supply' => 'файл заявки',
            'wh' => 'документ складу',
            default => $type,
        };
    }

    /**
     * @param AuditRow $row
     *
     * @return array<string, mixed>
     */
    private static function payload(array $row): array
    {
        $payload = json_decode($row['payload'], true);

        return is_array($payload) ? $payload : [];
    }
}
