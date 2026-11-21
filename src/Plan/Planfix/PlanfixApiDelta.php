<?php

namespace App\Plan\Planfix;

use Doctrine\DBAL\Connection;
use JsonException;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Добір із Planfix REST API того, чого немає в повному вивантаженні: нові задачі й нові
 * коментарі (з файлами) до вже перенесених задач. Результат — тека того самого вигляду, що й
 * вивантаження (tasks.json, actions.json, users.json, projects.json + manifest.jsonl з файлами),
 * тож далі працює звичайний plan:planfix:import з тією ж картою людей: ідемпотентність, відкат
 * і заглушки — ті самі.
 *
 * Ідентифікатори API і вивантаження різні: задача в API — це її номер (у вивантаженні `number`),
 * людина — «user:27» / «contact:5» (у вивантаженні числовий id). Відповідність людей і проєктів
 * беремо з того, що є і там, і там: автор того самого коментаря / та сама задача.
 * Нова задача (якої у вивантаженні нема) отримує стабільний id NEW_TASK_BASE + номер — повторний
 * добір дає той самий id, тож карта імпорту її вдруге не створить.
 *
 * Лише читання з Planfix. Токен — тільки зі змінної середовища процесу, не друкується.
 */
final readonly class PlanfixApiDelta
{
    public const string BASE_URL = 'https://buddetalua.planfix.com/rest';
    public const int NEW_TASK_BASE = 900_000_000;
    /** Скільки поспіль уже відомих коментарів треба побачити, щоб зупинити гортання (список — від нових). */
    private const int KNOWN_STREAK = 300;

    public function __construct(private HttpClientInterface $http, private Connection $db)
    {
    }

    /**
     * @param callable(string): void|null $log
     *
     * @return array<string, int>
     */
    public function build(string $baseDir, string $outDir, string $token, string $baseUrl = self::BASE_URL, ?callable $log = null): array
    {
        $log ??= static function (string $m): void {};
        $base = rtrim($baseDir, '/');
        $out = rtrim($outDir, '/');

        $baseTasks = self::readJson($base . '/tasks.json');
        $baseActions = self::readJson($base . '/actions.json');
        $byNumber = [];

        foreach ($baseTasks as $t) {
            $byNumber[(int) ($t['number'] ?? 0)] = $t;
        }

        $actionOwner = [];

        foreach ($baseActions as $a) {
            $actionOwner[(int) $a['id']] = (int) ($a['owner']['id'] ?? 0);
        }

        unset($baseActions);

        $api = new PlanfixApiClient($this->http, $baseUrl, $token);

        // 1. Задачі API: нові (номера нема у вивантаженні) + відповідність проєктів за спільними задачами.
        $apiTasks = $api->paged('/task/list', ['fields' => 'id,name,description,status,assigner,assignees,parent,project,dateTime,endDateTime,hasEndDate,priority,isDeleted'], 'tasks');
        $projectVotes = [];
        $newTasks = [];

        foreach ($apiTasks as $t) {
            $number = (int) $t['id'];

            if (isset($byNumber[$number])) {
                if (isset($t['project']['id'], $byNumber[$number]['project']['id'])) {
                    $projectVotes[(int) $t['project']['id']][(int) $byNumber[$number]['project']['id']] = ($projectVotes[(int) $t['project']['id']][(int) $byNumber[$number]['project']['id']] ?? 0) + 1;
                }
            } elseif (! ($t['isDeleted'] ?? false)) {
                $newTasks[$number] = $t;
            }
        }

        $log(sprintf('Задач в API: %d, нових: %d', count($apiTasks), count($newTasks)));

        // 2. Коментарі (від нових до старих): нові — яких нема у вивантаженні; відповідність людей —
        // за авторами тих самих коментарів. Гортаємо, доки не побачили досить уже відомих і не
        // впізнали всіх авторів нового.
        $newComments = [];
        $userVotes = [];
        $streak = 0;
        $seen = 0;
        $needed = [];

        foreach ($newTasks as $t) {
            if (str_starts_with((string) ($t['assigner']['id'] ?? ''), 'user:')) {
                $needed[(string) $t['assigner']['id']] = true;
            }
        }

        $api->each('/comment/list', ['fields' => 'id,dateTime,task,type,owner,description,files,isDeleted,isHidden'], 'comments', function (array $c) use (&$newComments, &$userVotes, &$streak, &$seen, &$needed, $actionOwner): bool {
            ++$seen;
            $id = (int) $c['id'];
            $owner = (string) ($c['owner']['id'] ?? '');

            if (isset($actionOwner[$id])) {
                ++$streak;

                if ($owner !== '' && $actionOwner[$id] !== 0) {
                    $userVotes[$owner][$actionOwner[$id]] = ($userVotes[$owner][$actionOwner[$id]] ?? 0) + 1;
                    unset($needed[$owner]);
                }
            } else {
                $streak = 0;

                if (! ($c['isDeleted'] ?? false)) {
                    $newComments[$id] = $c;

                    // Контакти (contact:N) можуть ніколи не зустрітися в старих коментарях — не чекаємо на них.
                    if (str_starts_with($owner, 'user:') && ! isset($userVotes[$owner])) {
                        $needed[$owner] = true;
                    }
                }
            }

            return ! ($streak >= self::KNOWN_STREAK && $needed === []);
        });

        $users = self::winners($userVotes);
        $projects = self::winners($projectVotes);
        $log(sprintf('Коментарів переглянуто: %d, нових: %d, людей впізнано: %d', $seen, count($newComments), count($users)));

        // 3. Тека вигляду вивантаження.
        $taskOut = [];
        $stats = ['tasks_new' => 0, 'tasks_touched' => 0, 'comments_new' => 0, 'comments_orphan' => 0, 'authors_unknown' => 0, 'files_new' => 0, 'files_known' => 0, 'files_failed' => 0];
        $unknown = [];
        $person = static function (?string $apiId) use ($users, &$unknown): int {
            if ($apiId === null || $apiId === '') {
                return 0;
            }

            if (! isset($users[$apiId])) {
                $unknown[$apiId] = true;

                return 0;
            }

            return $users[$apiId];
        };

        foreach ($newTasks as $number => $t) {
            $workers = [];

            foreach ($t['assignees']['users'] ?? [] as $u) {
                $pid = $person((string) ($u['id'] ?? ''));

                if ($pid !== 0) {
                    $workers[] = ['id' => $pid];
                }
            }

            $row = [
                'id' => self::NEW_TASK_BASE + $number,
                'number' => $number,
                'title' => (string) ($t['name'] ?? ''),
                'description' => (string) ($t['description'] ?? ''),
                'status' => (string) ($t['status']['name'] ?? ''),
                'createDate' => self::date($t['dateTime'] ?? null) ?? '',
                'owner' => ['id' => $person(isset($t['assigner']['id']) ? (string) $t['assigner']['id'] : null)],
                'workers' => ['users' => $workers, 'groups' => array_map(static fn ($g) => ['name' => (string) ($g['name'] ?? '')], $t['assignees']['groups'] ?? [])],
                'importance' => ($t['priority'] ?? '') === 'Urgent' ? 10 : 5,
                'hasEndDate' => (bool) ($t['hasEndDate'] ?? false),
                'endTime' => self::date($t['endDateTime'] ?? null) ?? '',
                'customData' => [],
            ];

            if (isset($t['project']['id'], $projects[(int) $t['project']['id']])) {
                $row['project'] = ['id' => $projects[(int) $t['project']['id']]];
            }

            if (isset($t['parent']['id'])) {
                $parent = (int) $t['parent']['id'];
                $row['parent'] = ['id' => isset($byNumber[$parent]) ? (int) $byNumber[$parent]['id'] : self::NEW_TASK_BASE + $parent];
            }

            $taskOut[$number] = $row;
            ++$stats['tasks_new'];
        }

        $actions = [];

        foreach ($newComments as $id => $c) {
            $number = (int) ($c['task']['id'] ?? 0);

            if (! isset($taskOut[$number])) {
                if (! isset($byNumber[$number])) {
                    ++$stats['comments_orphan'];
                    continue;
                }

                $taskOut[$number] = $byNumber[$number];
                ++$stats['tasks_touched'];
            }

            $actions[] = [
                'id' => $id,
                'task' => ['id' => (int) $taskOut[$number]['id']],
                'owner' => ['id' => $person(isset($c['owner']['id']) ? (string) $c['owner']['id'] : null)],
                'dateTime' => self::date($c['dateTime'] ?? null) ?? '',
                'type' => 'Comment',
                'description' => (string) ($c['description'] ?? ''),
                'hidden' => (bool) ($c['isHidden'] ?? false),
            ];
            ++$stats['comments_new'];
        }

        usort($actions, static fn ($a, $b) => $a['id'] <=> $b['id']);
        $stats['authors_unknown'] = count($unknown);

        if (! is_dir($out . '/files') && ! mkdir($out . '/files', 0770, true) && ! is_dir($out . '/files')) {
            throw new RuntimeException('Не вдалося створити ' . $out . '/files');
        }

        // 4. Файли: нових коментарів і самих нових задач; уже перенесені (є в карті) не качаємо.
        $known = array_flip(array_map('intval', $this->db->fetchFirstColumn("SELECT planfix_id FROM planfix_import_map WHERE planfix_type = 'file'")));
        $manifest = [];
        $wanted = [];

        foreach ($newComments as $id => $c) {
            if (! isset($taskOut[(int) ($c['task']['id'] ?? 0)])) {
                continue;
            }

            foreach ($c['files'] ?? [] as $f) {
                $wanted[(int) $f['id']] = ['file_id' => (int) $f['id'], 'name' => (string) ($f['name'] ?? ''), 'task' => (int) $c['task']['id'], 'comment' => $id];
            }
        }

        foreach (array_keys($newTasks) as $number) {
            foreach ($api->get(sprintf('/task/%d/files', $number))['files'] ?? [] as $f) {
                $wanted[(int) $f['id']] ??= ['file_id' => (int) $f['id'], 'name' => (string) ($f['name'] ?? ''), 'task' => $number, 'comment' => null];
            }
        }

        foreach ($wanted as $fileId => $f) {
            if (isset($known[$fileId])) {
                ++$stats['files_known'];
                continue;
            }

            $ext = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', substr((string) pathinfo($f['name'], PATHINFO_EXTENSION), 0, 12)));
            $local = sprintf('%s/files/%d%s', $out, $fileId, $ext !== '' ? '.' . $ext : '');

            try {
                if (! is_file($local) || filesize($local) === 0) {
                    $api->download(sprintf('/file/%d/download', $fileId), $local);
                }

                $manifest[] = $f + ['size' => (int) filesize($local), 'sha256' => (string) hash_file('sha256', $local), 'local_path' => $local, 'error' => null];
                ++$stats['files_new'];
            } catch (RuntimeException $e) {
                $manifest[] = $f + ['size' => null, 'sha256' => null, 'local_path' => null, 'error' => mb_substr($e->getMessage(), 0, 200)];
                ++$stats['files_failed'];
            }
        }

        self::writeJson($out . '/tasks.json', array_values($taskOut));
        self::writeJson($out . '/actions.json', $actions);
        self::writeJson($out . '/users.json', self::readJson($base . '/users.json'));
        self::writeJson($out . '/projects.json', is_file($base . '/projects.json') ? self::readJson($base . '/projects.json') : []);

        file_put_contents($out . '/' . PlanfixFileManifest::FILE, implode('', array_map(static fn ($m) => json_encode($m, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", $manifest)));
        chmod($out . '/' . PlanfixFileManifest::FILE, 0640);
        $stats['api_calls'] = $api->calls();
        self::writeJson($out . '/delta-report.json', $stats + ['unknown_people' => array_keys($unknown)]);

        return $stats;
    }

    /** Формат дат вивантаження «дд-мм-рррр гг:хх» — той самий годинник, що й у API (перевірено на спільних коментарях). */
    public static function date(mixed $value): ?string
    {
        if (! is_array($value) || ! isset($value['date'])) {
            return null;
        }

        return trim($value['date'] . ' ' . ($value['time'] ?? ''));
    }

    /**
     * @param array<int|string, array<int, int>> $votes
     *
     * @return array<int|string, int>
     */
    private static function winners(array $votes): array
    {
        $out = [];

        foreach ($votes as $key => $candidates) {
            arsort($candidates);
            $out[$key] = (int) array_key_first($candidates);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Немає ' . $path);
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('%s: не JSON (%s).', $path, $e->getMessage()), 0, $e);
        }

        if (! is_array($data) || ! array_is_list($data)) {
            throw new RuntimeException($path . ': очікувався список.');
        }

        return $data;
    }

    /** @param array<mixed> $data */
    private static function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        // Персональні дані (тексти, імена) — не для всіх на сервері.
        chmod($path, 0640);
    }
}
