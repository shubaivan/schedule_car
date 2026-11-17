<?php

namespace App\Plan\Zvyazok;

use App\Entity\TelegramUser;
use App\Plan\Drive\DriveClientFactory;
use App\Plan\Drive\SyncDriveFiles;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Share\FileType;
use App\Repository\TelegramUserRepository;
use App\Tasks\Service\TaskPresenter;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * «Звʼязок» — месенджер «Плану» поза задачами (ТЗ §21; Олександр 15.11: «аналог
 * месенджера, людина-людина, людина-команда… бачать тільки учасники… безліч чатів на
 * будь-які теми, закріпити, кількість непрочитаних»).
 *
 * Чати: особистий (двоє, один на пару) і груповий (назва, учасники, власник). Бачать лише
 * учасники; хто вийшов — більше не бачить. Особисте: закріплення, архів, «прочитано до».
 * Повідомлення: відповідь з переходом до оригіналу, @згадки, редагування з історією,
 * видалення для всіх (автор), галочки: ✓ надіслано, ✓✓ прочитали всі, у групі — «2/5».
 * Файли (Олександр 17.11): фото (стискаємо), офісні документи й PDF, до 20 МБ — ZvyazokFiles;
 * копія на Google Диску «План/<рік>/Звʼязок/№<id> <назва чату>/» (ZvyazokDriveFiles).
 * Новий учасник групи бачить усю історію (стрічка не фільтрується за joined_at).
 * Сповіщень у Telegram немає — лише лічильник непрочитаних (Олександр 17.11).
 *
 * Усі запити — DBAL з перевіркою членства; помилка доступу — ZvyazokException(404),
 * щоб за id не можна було дізнатись, що чат існує.
 */
final class ZvyazokService
{
    public const int MAX_BODY = 4000;
    public const int MAX_TITLE = 120;
    public const int PAGE = 50;

    public function __construct(
        private readonly Connection $db,
        private readonly TelegramUserRepository $users,
        private readonly TaskPresenter $presenter,
        private readonly ZvyazokAccess $access,
        private readonly ?ZvyazokFiles $files = null,
        private readonly ?MessageBusInterface $bus = null,
        private readonly ?DriveClientFactory $drives = null,
        private readonly ?PlanEventRecorder $events = null,
    ) {
    }

    /**
     * Ліва колонка: закріплені вгорі, далі за останньою активністю.
     *
     * @return list<array<string, mixed>>
     */
    public function chats(TelegramUser $me, string $q = '', bool $archived = false): array
    {
        $params = ['me' => (int) $me->getId(), 'meJson' => json_encode([(int) $me->getId()]), 'arch' => $archived ? 1 : 0];
        $where = 'm.user_id = :me AND m.left_at IS NULL AND m.archived = CAST(:arch AS BOOLEAN)';

        if (($q = trim($q)) !== '') {
            $params['q'] = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';
            // Пошук за назвою групи, іменами учасників і текстом повідомлень.
            $where .= " AND (LOWER(COALESCE(c.title, '')) LIKE :q
                OR EXISTS (SELECT 1 FROM plan_chat_member qm JOIN telegram_user qu ON qu.id = qm.user_id
                           WHERE qm.chat_id = c.id AND qm.user_id <> :me AND qm.left_at IS NULL
                             AND LOWER(COALESCE(qu.first_name, '') || ' ' || COALESCE(qu.last_name, '')) LIKE :q)
                OR EXISTS (SELECT 1 FROM plan_chat_message qx WHERE qx.chat_id = c.id AND qx.deleted_at IS NULL AND LOWER(qx.body) LIKE :q)
                OR EXISTS (SELECT 1 FROM plan_chat_attachment qa JOIN plan_chat_message qy ON qy.id = qa.message_id
                           WHERE qa.chat_id = c.id AND qy.deleted_at IS NULL AND LOWER(qa.original_name) LIKE :q))";
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT c.id, c.kind, c.title, c.last_activity_at, m.pinned, m.archived, m.last_read_id, m.role,
                    (SELECT COUNT(*) FROM plan_chat_message x WHERE x.chat_id = c.id AND x.id > m.last_read_id AND x.author_id <> :me AND x.deleted_at IS NULL) AS unread,
                    (SELECT COUNT(*) FROM plan_chat_message x WHERE x.chat_id = c.id AND x.id > m.last_read_id AND x.author_id <> :me AND x.deleted_at IS NULL AND x.mentions @> CAST(:meJson AS jsonb)) AS mentions,
                    (SELECT COUNT(*) FROM plan_chat_member am WHERE am.chat_id = c.id AND am.left_at IS NULL) AS members,
                    l.id AS last_id, l.body AS last_body, l.author_id AS last_author, l.created_at AS last_at, l.deleted_at AS last_deleted,
                    (SELECT COUNT(*) FROM plan_chat_attachment la WHERE la.message_id = l.id) AS last_files,
                    (SELECT la.original_name FROM plan_chat_attachment la WHERE la.message_id = l.id ORDER BY la.id LIMIT 1) AS last_file,
                    (SELECT om.user_id FROM plan_chat_member om WHERE om.chat_id = c.id AND om.user_id <> :me ORDER BY om.user_id LIMIT 1) AS other_id
               FROM plan_chat_member m
               JOIN plan_chat c ON c.id = m.chat_id
               LEFT JOIN LATERAL (SELECT y.id, y.body, y.author_id, y.created_at, y.deleted_at FROM plan_chat_message y
                                   WHERE y.chat_id = c.id ORDER BY y.id DESC LIMIT 1) l ON TRUE
              WHERE {$where}
              ORDER BY m.pinned DESC, c.last_activity_at DESC, c.id DESC
              LIMIT 300",
            $params,
        );

        $people = $this->people(array_merge(array_column($rows, 'other_id'), array_column($rows, 'last_author')));

        return array_map(function (array $r) use ($me, $people): array {
            $other = $r['kind'] === 'direct' && $r['other_id'] !== null ? ($people[(int) $r['other_id']] ?? null) : null;

            return [
                'id' => (int) $r['id'],
                'kind' => $r['kind'],
                'title' => $r['kind'] === 'direct' ? ($other['name'] ?? 'Особистий чат') : (string) $r['title'],
                'person' => $other,
                'members' => (int) $r['members'],
                'pinned' => (bool) $r['pinned'],
                'archived' => (bool) $r['archived'],
                'owner' => $r['role'] === 'owner',
                'unread' => (int) $r['unread'],
                'mentions' => (int) $r['mentions'],
                'activityAt' => self::iso($r['last_activity_at']),
                'last' => $r['last_id'] === null ? null : [
                    'id' => (int) $r['last_id'],
                    'text' => $r['last_deleted'] !== null ? 'Повідомлення видалено' : self::preview((string) $r['last_body'], (int) $r['last_files'], $r['last_file']),
                    'mine' => (int) $r['last_author'] === (int) $me->getId(),
                    'author' => ($people[(int) $r['last_author']]['name'] ?? null),
                    'at' => self::iso($r['last_at']),
                ],
            ];
        }, $rows);
    }

    /** Скільки непрочитаних у всіх неархівних чатах — бейдж «Звʼязку» в меню. */
    public function unreadTotal(TelegramUser $me): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM plan_chat_message x JOIN plan_chat_member m ON m.chat_id = x.chat_id
              WHERE m.user_id = :me AND m.left_at IS NULL AND m.archived = FALSE
                AND x.id > m.last_read_id AND x.author_id <> :me AND x.deleted_at IS NULL',
            ['me' => (int) $me->getId()],
        );
    }

    /** Особистий чат з людиною: є — той самий, немає — створюємо. */
    public function direct(TelegramUser $me, int $otherId): int
    {
        $other = $this->participant($otherId);

        if ($other->getId() === $me->getId()) {
            throw new ZvyazokException('Особистий чат — з іншою людиною.', 422);
        }

        $key = min((int) $me->getId(), $otherId) . ':' . max((int) $me->getId(), $otherId);

        return (int) $this->db->transactional(function () use ($me, $other, $key): int {
            $now = self::now();
            // Двоє одночасно відкрили розмову — ON CONFLICT: другий просто отримає той самий чат.
            $id = $this->db->fetchOne(
                'INSERT INTO plan_chat (id, kind, title, direct_key, created_by, created_at, last_activity_at)
                 VALUES (nextval(\'plan_chat_id_seq\'), \'direct\', NULL, ?, ?, ?, ?) ON CONFLICT (direct_key) DO NOTHING RETURNING id',
                [$key, $me->getId(), $now, $now],
            );

            if ($id === false) {
                $existing = (int) $this->db->fetchOne('SELECT id FROM plan_chat WHERE direct_key = ?', [$key]);
                // Повернувся до розмови — чат знову в списку, навіть якщо був в архіві.
                $this->db->executeStatement('UPDATE plan_chat_member SET archived = FALSE, left_at = NULL WHERE chat_id = ? AND user_id = ?', [$existing, $me->getId()]);

                return $existing;
            }

            $this->addMember((int) $id, (int) $me->getId(), 'owner');
            $this->addMember((int) $id, (int) $other->getId(), 'member');

            return (int) $id;
        });
    }

    /** @param list<int> $memberIds */
    public function group(TelegramUser $me, string $title, array $memberIds): int
    {
        $title = trim($title);

        if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
            throw new ZvyazokException(sprintf('Назва чату — від 1 до %d символів.', self::MAX_TITLE), 422);
        }

        $members = [];

        foreach (array_unique(array_map('intval', $memberIds)) as $id) {
            if ($id !== (int) $me->getId()) {
                $members[] = $this->participant($id);
            }
        }

        if ($members === []) {
            throw new ZvyazokException('Додайте хоча б одного учасника.', 422);
        }

        return (int) $this->db->transactional(function () use ($me, $title, $members): int {
            $id = $this->insertChat('group', $title, $me, null);
            $this->addMember($id, (int) $me->getId(), 'owner');

            foreach ($members as $m) {
                $this->addMember($id, (int) $m->getId(), 'member');
            }

            return $id;
        });
    }

    /** @return array<string, mixed> шапка чату й учасники */
    public function chat(TelegramUser $me, int $chatId): array
    {
        $row = $this->membership($me, $chatId);
        $members = $this->db->fetchAllAssociative(
            'SELECT user_id, role, last_read_id FROM plan_chat_member WHERE chat_id = ? AND left_at IS NULL ORDER BY role DESC, user_id',
            [$chatId],
        );
        $people = $this->people(array_column($members, 'user_id'));
        $list = array_values(array_filter(array_map(static fn (array $m) => isset($people[(int) $m['user_id']]) ? [
            ...$people[(int) $m['user_id']],
            'owner' => $m['role'] === 'owner',
            'lastReadId' => (int) $m['last_read_id'],
        ] : null, $members)));
        $other = $row['kind'] === 'direct' ? array_first(array_filter($list, static fn (array $p) => $p['id'] !== $me->getId())) ?? null : null;

        return [
            'id' => $chatId,
            'kind' => $row['kind'],
            'title' => $row['kind'] === 'direct' ? ($other['name'] ?? 'Особистий чат') : (string) $row['title'],
            'person' => $other,
            'owner' => $row['role'] === 'owner',
            'pinned' => (bool) $row['pinned'],
            'archived' => (bool) $row['archived'],
            'lastReadId' => (int) $row['last_read_id'],
            'members' => $list,
        ];
    }

    /**
     * Стрічка: до 50 повідомлень перед `before` або після `after` (дочитати нове).
     *
     * @return array{items: list<array<string, mixed>>, more: bool}
     */
    public function messages(TelegramUser $me, int $chatId, ?int $before = null, ?int $after = null): array
    {
        $this->membership($me, $chatId);
        $params = ['chat' => $chatId];
        $sql = 'SELECT id, author_id, body, reply_to_id, mentions, created_at, edited_at, deleted_at FROM plan_chat_message WHERE chat_id = :chat';

        if ($after !== null) {
            $sql .= ' AND id > :after ORDER BY id ASC LIMIT ' . (self::PAGE + 1);
            $params['after'] = $after;
        } else {
            if ($before !== null) {
                $sql .= ' AND id < :before';
                $params['before'] = $before;
            }

            $sql .= ' ORDER BY id DESC LIMIT ' . (self::PAGE + 1);
        }

        $rows = $this->db->fetchAllAssociative($sql, $params);
        $more = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);

        if ($after === null) {
            $rows = array_reverse($rows);
        }

        return ['items' => $this->present($chatId, $rows, $me), 'more' => $more];
    }

    /**
     * @param list<int> $mentionIds
     * @param list<UploadedFile> $files фото, документи, PDF (ZvyazokFiles), до MAX_FILES
     *
     * @return array<string, mixed>
     */
    public function post(TelegramUser $me, int $chatId, string $body, ?int $replyTo, array $mentionIds, array $files = []): array
    {
        $this->membership($me, $chatId);
        $body = self::cleanBody($body, $files !== []);

        if (count($files) > ZvyazokFiles::MAX_FILES) {
            throw new ZvyazokException(sprintf('За раз — до %d файлів.', ZvyazokFiles::MAX_FILES), 422);
        }

        if ($files !== [] && $this->files === null) {
            throw new ZvyazokException('Файли тут не приймаються.', 422);
        }

        // Розмір і тип — до будь-якого запису.
        $checked = array_map(static fn (UploadedFile $f) => [$f, ...ZvyazokFiles::check($f)], $files);

        if ($replyTo !== null && $this->db->fetchOne('SELECT 1 FROM plan_chat_message WHERE id = ? AND chat_id = ?', [$replyTo, $chatId]) === false) {
            throw new ZvyazokException('Повідомлення, на яке ви відповідаєте, не знайдено.', 422);
        }

        // Згадати можна лише учасника цього чату.
        $mentions = $mentionIds === [] ? [] : array_map('intval', $this->db->fetchFirstColumn(
            'SELECT user_id FROM plan_chat_member WHERE chat_id = :chat AND left_at IS NULL AND user_id IN (:ids) AND user_id <> :me',
            ['chat' => $chatId, 'ids' => array_values(array_unique(array_map('intval', $mentionIds))), 'me' => (int) $me->getId()],
            ['ids' => ArrayParameterType::INTEGER],
        ));

        /** @var list<array{id: int, path: string}> $written */
        $written = [];
        /** @var list<array{kind: string, prepared: array<string, mixed>}> $ready */
        $ready = [];

        try {
            // Стиснення фото — ДО транзакції: важке, і рядки повідомлень не тримаються заблокованими.
            foreach ($checked as [$file, $kind, $detected]) {
                $ready[] = ['kind' => $kind, 'prepared' => $this->files?->prepare($file, $kind, $detected) ?? []];
            }

            $id = (int) $this->db->transactional(function () use ($me, $chatId, $body, $replyTo, $mentions, $ready, &$written): int {
                $now = self::now();
                $id = (int) $this->db->fetchOne(
                    'INSERT INTO plan_chat_message (id, chat_id, author_id, body, reply_to_id, mentions, created_at)
                     VALUES (nextval(\'plan_chat_message_id_seq\'), ?, ?, ?, ?, CAST(? AS jsonb), ?) RETURNING id',
                    [$chatId, $me->getId(), $body, $replyTo, json_encode($mentions), $now],
                );

                foreach ($ready as $r) {
                    $written[] = $this->storeFile($me, $chatId, $id, $r['prepared'], $r['kind']);
                }

                $this->db->executeStatement('UPDATE plan_chat SET last_activity_at = ? WHERE id = ?', [$now, $chatId]);
                // Своє — прочитане; новe повідомлення повертає чат з архіву всім учасникам.
                $this->db->executeStatement('UPDATE plan_chat_member SET last_read_id = ? WHERE chat_id = ? AND user_id = ?', [$id, $chatId, $me->getId()]);
                $this->db->executeStatement('UPDATE plan_chat_member SET archived = FALSE WHERE chat_id = ? AND left_at IS NULL', [$chatId]);

                return $id;
            });
        } catch (Throwable $e) {
            // Повідомлення не збереглося — файлів у сховищі теж не лишаємо.
            foreach ($written as $w) {
                $this->files?->discard($w['path'], $w['id']);
            }

            throw $e;
        } finally {
            foreach ($ready as $r) {
                if (($r['prepared']['temp'] ?? false) === true) {
                    @unlink((string) $r['prepared']['path']);
                }
            }
        }

        // Мініатюри — після коміту (не тримаємо транзакцію; не вийшло — покажемо значок).
        foreach ($written as $w) {
            if ($w['image']) {
                $this->files?->thumb($w['id'], $w['path']);
            }
        }

        $this->toDrive(array_column($written, 'id'));

        return $this->one($me, $chatId, $id);
    }

    /**
     * Файл на місце й рядок у plan_chat_attachment (у транзакції повідомлення).
     *
     * @param array<string, mixed> $prepared з ZvyazokFiles::prepare()
     *
     * @return array{id: int, path: string, image: bool}
     */
    private function storeFile(TelegramUser $me, int $chatId, int $messageId, array $prepared, string $kind): array
    {
        $files = $this->files ?? throw new ZvyazokException('Файли тут не приймаються.', 422);
        $id = (int) $this->db->fetchOne("SELECT nextval('plan_chat_attachment_id_seq')");
        $at = new DateTimeImmutable();
        $sha = (string) hash_file('sha256', (string) $prepared['path']);
        $relative = $files->put($id, $sha, $at, (string) $prepared['name'], (string) $prepared['path']);

        try {
            $this->insertAttachment($id, $messageId, $chatId, $me, $prepared, $sha, $relative, $kind, $at);
        } catch (Throwable $e) {
            $files->discard($relative, $id);

            throw $e;
        }

        return ['id' => $id, 'path' => $relative, 'image' => $kind === ZvyazokFiles::KIND_IMAGE];
    }

    /** @param array<string, mixed> $prepared */
    private function insertAttachment(int $id, int $messageId, int $chatId, TelegramUser $me, array $prepared, string $sha, string $relative, string $kind, DateTimeImmutable $at): void
    {
        $this->db->insert('plan_chat_attachment', [
            'id' => $id,
            'message_id' => $messageId,
            'chat_id' => $chatId,
            'uploaded_by_id' => $me->getId(),
            'original_name' => mb_substr((string) $prepared['name'], 0, 255),
            'mime' => mb_substr((string) $prepared['mime'], 0, 127),
            'size' => (int) $prepared['size'],
            'original_size' => (int) $prepared['originalSize'],
            'sha256' => $sha,
            'path' => $relative,
            'kind' => $kind,
            'width' => $prepared['width'],
            'height' => $prepared['height'],
            'created_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Файл повідомлення для віддачі: лише учасник чату, повідомлення не видалене.
     * Чужий/неіснуючий — 404 однаково.
     *
     * @return array<string, mixed>
     */
    public function file(TelegramUser $me, int $fileId): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT a.id, a.chat_id, a.original_name, a.mime, a.size, a.sha256, a.path, a.kind
               FROM plan_chat_attachment a JOIN plan_chat_message m ON m.id = a.message_id
               JOIN plan_chat_member cm ON cm.chat_id = a.chat_id AND cm.user_id = ? AND cm.left_at IS NULL
              WHERE a.id = ? AND m.deleted_at IS NULL',
            [$me->getId(), $fileId],
        );

        if ($row === false) {
            throw new ZvyazokException('Файл не знайдено.', 404);
        }

        return $row;
    }

    /**
     * Одразу на Диск, як файли задач (DriveEnqueue): лише коли події йдуть через воркер
     * і розкладка «План»; інакше файл чекає крону plan:drive-sync (рядок уже pending).
     *
     * @param list<int> $ids
     */
    private function toDrive(array $ids): void
    {
        if ($ids === [] || $this->bus === null || $this->drives === null || $this->events === null) {
            return;
        }

        if ($this->events->isSync() || ! $this->drives->isPlanLayout() || ! $this->drives->isConfigured()) {
            return;
        }

        try {
            $this->bus->dispatch(new SyncDriveFiles(FileType::Zvyazok->value, $ids));
        } catch (Throwable) {
            // Не страшно: крон plan:drive-sync підбере pending.
        }
    }

    /** @return array<string, mixed> */
    public function edit(TelegramUser $me, int $messageId, string $body): array
    {
        $row = $this->ownMessage($me, $messageId);
        $hasFiles = $this->db->fetchOne('SELECT 1 FROM plan_chat_attachment WHERE message_id = ? LIMIT 1', [$messageId]) !== false;
        $body = self::cleanBody($body, $hasFiles);

        if ($body !== $row['body']) {
            $this->db->transactional(function () use ($messageId, $row, $body): void {
                $this->db->executeStatement(
                    'INSERT INTO plan_chat_message_edit (id, message_id, old_body, edited_at) VALUES (nextval(\'plan_chat_message_edit_id_seq\'), ?, ?, ?)',
                    [$messageId, $row['body'], self::now()],
                );
                $this->db->executeStatement('UPDATE plan_chat_message SET body = ?, edited_at = ? WHERE id = ?', [$body, self::now(), $messageId]);
                // Згадка, яку прибрали з тексту, — більше не згадка.
                $kept = [];

                foreach (json_decode((string) $row['mentions'], true) ?: [] as $id) {
                    $user = $this->users->find((int) $id);

                    if ($user !== null && str_contains($body, '@' . $user->shortName())) {
                        $kept[] = (int) $id;
                    }
                }

                $this->db->executeStatement('UPDATE plan_chat_message SET mentions = CAST(? AS jsonb) WHERE id = ?', [json_encode($kept), $messageId]);
            });
        }

        return $this->one($me, (int) $row['chat_id'], $messageId);
    }

    /** Видалити для всіх — лише автор; текст ховаємо, рядок лишається (історія розмови). */
    public function delete(TelegramUser $me, int $messageId): void
    {
        $this->ownMessage($me, $messageId);
        $this->db->transactional(function () use ($messageId): void {
            $this->db->executeStatement('UPDATE plan_chat_message SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL', [self::now(), $messageId]);
            // «Для всіх» — і текст, і його попередні версії.
            $this->db->executeStatement('DELETE FROM plan_chat_message_edit WHERE message_id = ?', [$messageId]);
        });
    }

    /** @return list<array{text: string, at: ?string}> попередні версії, від найстарішої */
    public function history(TelegramUser $me, int $messageId): array
    {
        $row = $this->db->fetchAssociative('SELECT chat_id, deleted_at FROM plan_chat_message WHERE id = ?', [$messageId]);

        if ($row === false) {
            throw new ZvyazokException('Повідомлення не знайдено.', 404);
        }

        $this->membership($me, (int) $row['chat_id']);

        // Видалене для всіх — разом з усіма попередніми версіями.
        if ($row['deleted_at'] !== null) {
            return [];
        }

        return array_map(
            static fn (array $r) => ['text' => (string) $r['old_body'], 'at' => self::iso($r['edited_at'])],
            $this->db->fetchAllAssociative('SELECT old_body, edited_at FROM plan_chat_message_edit WHERE message_id = ? ORDER BY id', [$messageId]),
        );
    }

    /** Прочитано до повідомлення (не далі за останнє в чаті; назад не відкочується). */
    public function read(TelegramUser $me, int $chatId, int $messageId): int
    {
        $this->membership($me, $chatId);
        $max = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM plan_chat_message WHERE chat_id = ?', [$chatId]);
        $to = min($messageId, $max);
        $this->db->executeStatement('UPDATE plan_chat_member SET last_read_id = GREATEST(last_read_id, ?) WHERE chat_id = ? AND user_id = ?', [$to, $chatId, $me->getId()]);

        return $to;
    }

    public function setFlag(TelegramUser $me, int $chatId, string $flag, bool $on): void
    {
        $this->membership($me, $chatId);
        $column = match ($flag) {
            'pinned' => 'pinned',
            'archived' => 'archived',
            default => throw new ZvyazokException('Невідома дія.', 422),
        };
        $this->db->executeStatement("UPDATE plan_chat_member SET {$column} = ? WHERE chat_id = ? AND user_id = ?", [$on ? 1 : 0, $chatId, $me->getId()], [ParameterType::BOOLEAN]);
    }

    /**
     * Груповий чат: назва й склад — власник; вийти може кожен (власник — якщо лишається інший власник
     * або передає роль найдавнішому учаснику).
     *
     * @param list<int> $add
     * @param list<int> $remove
     */
    public function updateGroup(TelegramUser $me, int $chatId, ?string $title, array $add, array $remove): void
    {
        $row = $this->membership($me, $chatId);

        if ($row['kind'] !== 'group' || $row['role'] !== 'owner') {
            throw new ZvyazokException('Назву й учасників змінює власник групового чату.', 403);
        }

        $people = array_map(fn (int $id) => $this->participant($id), array_values(array_unique(array_map('intval', $add))));

        $this->db->transactional(function () use ($me, $chatId, $title, $people, $remove): void {
            if ($title !== null) {
                $title = trim($title);

                if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
                    throw new ZvyazokException(sprintf('Назва чату — від 1 до %d символів.', self::MAX_TITLE), 422);
                }

                $this->db->executeStatement('UPDATE plan_chat SET title = ? WHERE id = ?', [$title, $chatId]);
            }

            foreach ($people as $p) {
                $this->addMember($chatId, (int) $p->getId(), 'member');
            }

            foreach (array_unique(array_map('intval', $remove)) as $id) {
                if ($id !== (int) $me->getId()) {
                    $this->db->executeStatement('UPDATE plan_chat_member SET left_at = ? WHERE chat_id = ? AND user_id = ? AND left_at IS NULL', [self::now(), $chatId, $id]);
                }
            }
        });
    }

    public function leave(TelegramUser $me, int $chatId): void
    {
        $row = $this->membership($me, $chatId);

        if ($row['kind'] !== 'group') {
            throw new ZvyazokException('З особистого чату не виходять — його можна сховати в архів.', 422);
        }

        $this->db->transactional(function () use ($me, $chatId, $row): void {
            $this->db->executeStatement('UPDATE plan_chat_member SET left_at = ?, role = \'member\' WHERE chat_id = ? AND user_id = ?', [self::now(), $chatId, $me->getId()]);

            if ($row['role'] === 'owner' && $this->db->fetchOne("SELECT 1 FROM plan_chat_member WHERE chat_id = ? AND left_at IS NULL AND role = 'owner'", [$chatId]) === false) {
                $this->db->executeStatement(
                    "UPDATE plan_chat_member SET role = 'owner' WHERE chat_id = ? AND user_id = (SELECT user_id FROM plan_chat_member WHERE chat_id = ? AND left_at IS NULL ORDER BY joined_at, user_id LIMIT 1)",
                    [$chatId, $chatId],
                );
            }
        });
    }

    /** Кого можна додати в чат: живі люди «Плану», яким «Звʼязок» відкритий. @return list<array<string, mixed>> */
    public function directory(TelegramUser $me): array
    {
        $out = [];

        foreach ($this->users->findBy([], ['first_name' => 'ASC', 'last_name' => 'ASC']) as $u) {
            if ($u->getId() !== $me->getId() && $this->access->can($u) && ($p = $this->presenter->person($u)) !== null) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function membership(TelegramUser $me, int $chatId): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT c.kind, c.title, m.role, m.pinned, m.archived, m.last_read_id FROM plan_chat c
               JOIN plan_chat_member m ON m.chat_id = c.id AND m.user_id = ? AND m.left_at IS NULL WHERE c.id = ?',
            [$me->getId(), $chatId],
        );

        if ($row === false) {
            throw new ZvyazokException('Чат не знайдено.', 404);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function ownMessage(TelegramUser $me, int $messageId): array
    {
        $row = $this->db->fetchAssociative('SELECT chat_id, author_id, body, mentions, deleted_at FROM plan_chat_message WHERE id = ?', [$messageId]);

        if ($row === false) {
            throw new ZvyazokException('Повідомлення не знайдено.', 404);
        }

        $this->membership($me, (int) $row['chat_id']);

        if ((int) $row['author_id'] !== (int) $me->getId() || $row['deleted_at'] !== null) {
            throw new ZvyazokException('Змінювати й видаляти можна лише свої повідомлення.', 403);
        }

        return $row;
    }

    private function participant(int $id): TelegramUser
    {
        $user = $this->users->find($id);

        if (! $this->access->can($user)) {
            throw new ZvyazokException('Цієї людини немає серед користувачів «Звʼязку».', 422);
        }

        return $user;
    }

    private function insertChat(string $kind, ?string $title, TelegramUser $me, ?string $key): int
    {
        $now = self::now();

        return (int) $this->db->fetchOne(
            'INSERT INTO plan_chat (id, kind, title, direct_key, created_by, created_at, last_activity_at)
             VALUES (nextval(\'plan_chat_id_seq\'), ?, ?, ?, ?, ?, ?) RETURNING id',
            [$kind, $title, $key, $me->getId(), $now, $now],
        );
    }

    private function addMember(int $chatId, int $userId, string $role): void
    {
        // Повернення того, хто вийшов, — той самий рядок; «прочитано» — з моменту повернення.
        $this->db->executeStatement(
            'INSERT INTO plan_chat_member (chat_id, user_id, role, pinned, archived, last_read_id, joined_at)
             VALUES (:chat, :user, :role, FALSE, FALSE, (SELECT COALESCE(MAX(id), 0) FROM plan_chat_message WHERE chat_id = :chat), :now)
             ON CONFLICT (chat_id, user_id) DO UPDATE SET left_at = NULL, archived = FALSE,
                 last_read_id = CASE WHEN plan_chat_member.left_at IS NULL THEN plan_chat_member.last_read_id ELSE EXCLUDED.last_read_id END',
            ['chat' => $chatId, 'user' => $userId, 'role' => $role, 'now' => self::now()],
        );
    }

    /** @return array<string, mixed> */
    private function one(TelegramUser $me, int $chatId, int $id): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT id, author_id, body, reply_to_id, mentions, created_at, edited_at, deleted_at FROM plan_chat_message WHERE id = ?', [$id]);

        return $this->present($chatId, $rows, $me)[0];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function present(int $chatId, array $rows, TelegramUser $me): array
    {
        if ($rows === []) {
            return [];
        }

        $reads = array_map(
            static fn (array $m) => ['user' => (int) $m['user_id'], 'read' => (int) $m['last_read_id']],
            $this->db->fetchAllAssociative('SELECT user_id, last_read_id FROM plan_chat_member WHERE chat_id = ? AND left_at IS NULL', [$chatId]),
        );
        $replyIds = array_values(array_filter(array_map(static fn (array $r) => $r['reply_to_id'] !== null ? (int) $r['reply_to_id'] : null, $rows)));
        $replies = [];

        if ($replyIds !== []) {
            foreach ($this->db->fetchAllAssociative(
                'SELECT x.id, x.author_id, x.body, x.deleted_at,
                        (SELECT COUNT(*) FROM plan_chat_attachment a WHERE a.message_id = x.id) AS files,
                        (SELECT a.original_name FROM plan_chat_attachment a WHERE a.message_id = x.id ORDER BY a.id LIMIT 1) AS file
                   FROM plan_chat_message x WHERE x.id IN (?) AND x.chat_id = ?',
                [$replyIds, $chatId],
                [ArrayParameterType::INTEGER],
            ) as $r) {
                $replies[(int) $r['id']] = $r;
            }
        }

        $people = $this->people([...array_column($rows, 'author_id'), ...array_column($replies, 'author_id')]);
        $attachments = $this->attachments(array_map(static fn (array $r) => (int) $r['id'], array_filter($rows, static fn (array $r) => $r['deleted_at'] === null)));

        return array_map(function (array $r) use ($reads, $replies, $people, $me, $attachments): array {
            $id = (int) $r['id'];
            $author = (int) $r['author_id'];
            $others = array_values(array_filter($reads, static fn (array $m) => $m['user'] !== $author));
            $readBy = count(array_filter($others, static fn (array $m) => $m['read'] >= $id));
            $deleted = $r['deleted_at'] !== null;
            $reply = $r['reply_to_id'] !== null ? ($replies[(int) $r['reply_to_id']] ?? null) : null;

            return [
                'id' => $id,
                'author' => $people[$author] ?? null,
                'mine' => $author === (int) $me->getId(),
                'text' => $deleted ? '' : (string) $r['body'],
                'deleted' => $deleted,
                'edited' => $r['edited_at'] !== null,
                'at' => self::iso($r['created_at']),
                'mentions' => array_map('intval', json_decode((string) $r['mentions'], true) ?: []),
                'files' => $deleted ? [] : ($attachments[$id] ?? []),
                'reply' => $reply === null ? null : [
                    'id' => (int) $reply['id'],
                    'author' => $people[(int) $reply['author_id']]['name'] ?? null,
                    'text' => $reply['deleted_at'] !== null ? 'Повідомлення видалено' : self::preview((string) $reply['body'], (int) $reply['files'], $reply['file'], 160),
                ],
                // Галочки: ✓ — збережено (доставлено), ✓✓ — прочитали всі інші учасники.
                'readBy' => $readBy,
                'readOf' => count($others),
            ];
        }, $rows);
    }

    /**
     * @param list<mixed> $ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function people(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];

        foreach ($ids === [] ? [] : $this->users->findBy(['id' => $ids]) as $u) {
            $p = $this->presenter->person($u);

            if ($p !== null) {
                $out[(int) $u->getId()] = $p;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $messageIds
     *
     * @return array<int, list<array<string, mixed>>> message id → файли
     */
    private function attachments(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $out = [];

        foreach ($this->db->fetchAllAssociative(
            'SELECT id, message_id, original_name, mime, size, original_size, kind, width, height FROM plan_chat_attachment WHERE message_id IN (?) ORDER BY id',
            [array_values($messageIds)],
            [ArrayParameterType::INTEGER],
        ) as $a) {
            $id = (int) $a['id'];
            $base = '/api/plan/zvyazok/files/' . $id;
            $out[(int) $a['message_id']][] = [
                'id' => $id,
                'name' => (string) $a['original_name'],
                'mime' => (string) $a['mime'],
                'size' => (int) $a['size'],
                'originalSize' => (int) $a['original_size'],
                'kind' => (string) $a['kind'],
                'width' => $a['width'] !== null ? (int) $a['width'] : null,
                'height' => $a['height'] !== null ? (int) $a['height'] : null,
                'thumbUrl' => $a['kind'] === ZvyazokFiles::KIND_IMAGE ? $base . '/thumb' : null,
                'viewUrl' => $base . '/inline',
                'downloadUrl' => $base . '/download',
            ];
        }

        return $out;
    }

    /** Рядок у списку чатів і в цитаті: текст, а повідомлення з самим файлом — «📎 назва (+2)». */
    private static function preview(string $body, int $files, mixed $first, int $max = 140): string
    {
        if (trim($body) !== '' || $files === 0) {
            return mb_substr($body, 0, $max);
        }

        return mb_substr('📎 ' . (string) $first . ($files > 1 ? sprintf(' (+%d)', $files - 1) : ''), 0, $max);
    }

    private static function cleanBody(string $body, bool $hasFiles = false): string
    {
        $body = trim(str_replace("\r\n", "\n", $body));

        if ($body === '' && ! $hasFiles) {
            throw new ZvyazokException('Порожнє повідомлення.', 422);
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            throw new ZvyazokException(sprintf('Повідомлення — до %d символів.', self::MAX_BODY), 422);
        }

        return $body;
    }

    private static function now(): string
    {
        return new DateTimeImmutable()->format('Y-m-d H:i:s');
    }

    private static function iso(mixed $value): ?string
    {
        return $value === null ? null : new DateTimeImmutable((string) $value)->format(DateTimeInterface::ATOM);
    }
}
