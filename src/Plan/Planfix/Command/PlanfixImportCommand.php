<?php

namespace App\Plan\Planfix\Command;

use App\Plan\Planfix\PlanfixApplier;
use App\Plan\Planfix\PlanfixImportGuard;
use App\Plan\Planfix\PlanfixMapper;
use App\Plan\Planfix\PlanfixStaging;
use DateTime;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Перенесення задач і угод з вивантаження Planfix.
 *
 *     php bin/console plan:planfix:import /шлях/до/вивантаження                 # dry-run: лише staging + звіт
 *     php bin/console plan:planfix:import /шлях --batch=pf-1 --user-map=map.json
 *     PLANFIX_IMPORT_ALLOW=1 php bin/console plan:planfix:import /шлях --batch=pf-1 --apply
 *     php bin/console plan:planfix:rollback pf-1
 *
 * Без --apply у задачі Плану не пишеться нічого — лише таблиці planfix_import_*.
 * --apply пише напряму (DBAL), без подій журналу: ні Telegram, ні push, ні Хроніки, ні Диска.
 * Повторний --apply нічого не дублює. Пошук після --apply: plan:search:reindex.
 *
 * --user-map — JSON {"<planfix id>": <id людини в Плані>}; решта людей стане заглушками
 * «Колишній співробітник #<id>». Автоматично людей не зіставляємо — лише підказки.
 */
#[AsCommand(name: 'plan:planfix:import', description: 'Задачі й угоди з вивантаження Planfix → staging (dry-run) або в задачі Плану (--apply)')]
final class PlanfixImportCommand extends Command
{
    public function __construct(
        private readonly PlanfixStaging $staging,
        private readonly PlanfixApplier $applier,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dir', InputArgument::REQUIRED, 'Тека з tasks.json, actions.json, users.json, projects.json')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Ідентифікатор партії (за замовчуванням pf-<дата-час>)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Записати в задачі Плану (потрібно ' . PlanfixImportGuard::ENV . '=1)')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Лише задачі, створені з цієї дати (РРРР-ММ-ДД)')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'all | deal | task', 'all')
            ->addOption('events', null, InputOption::VALUE_REQUIRED, 'none (лише коментарі) | meaningful (+ учасники, зміни) | all (+ статуси, нагадування…)', PlanfixMapper::EVENTS_MEANINGFUL)
            ->addOption('user-map', null, InputOption::VALUE_REQUIRED, 'JSON {"planfix id": id людини в Плані}')
            ->addOption('system-ids', null, InputOption::VALUE_REQUIRED, 'Службові облікові записи Planfix через кому (роботи, сценарії) → автор «Planfix (система)»')
            ->addOption('samples', null, InputOption::VALUE_REQUIRED, 'Скільки задач показати прикладом', '5')
            ->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Задач в одній транзакції --apply', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        if ($apply && ! PlanfixImportGuard::allowed()) {
            $io->error('--apply пише в задачі. Запустіть з ' . PlanfixImportGuard::ENV . '=1 у середовищі процесу (навмисно, щоб не спрацювало на проді випадково).');

            return Command::FAILURE;
        }

        $only = (string) $input->getOption('only');
        $events = (string) $input->getOption('events');

        if (! in_array($only, ['all', PlanfixMapper::KIND_DEAL, PlanfixMapper::KIND_TASK], true) || ! in_array($events, [PlanfixMapper::EVENTS_NONE, PlanfixMapper::EVENTS_MEANINGFUL, PlanfixMapper::EVENTS_ALL], true)) {
            $io->error('--only: all | deal | task; --events: none | meaningful | all.');

            return Command::INVALID;
        }

        $since = null;

        if ($input->getOption('since') !== null) {
            $since = DateTime::createFromFormat('!Y-m-d', (string) $input->getOption('since'), new DateTimeZone('Europe/Kyiv'));

            if ($since === false) {
                $io->error('--since: дата РРРР-ММ-ДД.');

                return Command::INVALID;
            }
        }

        try {
            $userMap = $this->userMap($input->getOption('user-map'));
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $batch = (string) ($input->getOption('batch') ?? 'pf-' . new DateTime('now', new DateTimeZone('Europe/Kyiv'))->format('Ymd-His'));

        if (! preg_match('/^[A-Za-z0-9._-]{1,40}$/', $batch)) {
            $io->error('--batch: латиниця, цифри, «.-_», до 40 символів.');

            return Command::INVALID;
        }

        // actions.json — десятки МБ; json_decode тримає все в памʼяті.
        if (ini_get('memory_limit') !== '-1') {
            ini_set('memory_limit', '2G');
        }

        $dir = (string) $input->getArgument('dir');

        try {
            $stats = $this->staging->stage($dir, $batch, ['since' => $since ?: null, 'only' => $only, 'events' => $events]);
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title(sprintf('Planfix → План, партія %s%s', $batch, $apply ? '' : ' [dry-run: лише staging]'));
        $io->definitionList(
            ['Задач у вивантаженні' => $stats['tasks_in_export']],
            ['Дій у вивантаженні' => $stats['actions_in_export']],
            ['Беремо: угод' => $stats['deals']],
            ['Беремо: задач' => $stats['tasks']],
            ['Пропущено фільтром (--since/--only)' => $stats['skipped_by_filter']],
            ['Дій → повідомлень у чат' => $stats['actions_staged']],
            ['Людей (з них діючих у Planfix)' => sprintf('%d (%d)', $stats['people'], $stats['people_active'])],
        );

        $io->section('Статуси Planfix → План');
        $io->table(['Planfix → План', 'Задач'], array_map(static fn ($k, $v) => [$k, $v], array_keys((array) $stats['statuses']), (array) $stats['statuses']));

        $io->section('Дії');
        $io->table(['Рішення: тип', 'Кількість'], array_map(static fn ($k, $v) => [$k, $v], array_keys((array) $stats['actions']), (array) $stats['actions']));

        $this->people($io, $batch, $userMap);
        $this->samples($io, $batch, max(0, (int) $input->getOption('samples')));

        if (! $apply) {
            $io->success(sprintf('Dry-run: staging партії %s готовий, задач Плану не змінено. Застосувати: %s=1 … --batch=%1$s --apply', $batch, PlanfixImportGuard::ENV));

            return Command::SUCCESS;
        }

        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM planfix_import_task WHERE batch_id = ?', [$batch]);
        $io->progressStart($total);
        $systemIds = array_values(array_filter(array_map('intval', explode(',', (string) $input->getOption('system-ids')))));
        $result = $this->applier->apply($batch, $userMap, max(1, (int) $input->getOption('chunk')), static fn (int $n) => $io->progressAdvance($n), $systemIds);
        $io->progressFinish();
        $io->table(['Застосовано', 'Кількість'], array_map(static fn ($k, $v) => [$k, $v], array_keys($result), $result));
        $io->success(sprintf('Партію %s застосовано. Пошук: plan:search:reindex. Відкат: plan:planfix:rollback %1$s', $batch));

        return Command::SUCCESS;
    }

    /** @return array<int, int> */
    private function userMap(mixed $path): array
    {
        if ($path === null || $path === '') {
            return [];
        }

        if (! is_file((string) $path)) {
            throw new RuntimeException('--user-map: файлу немає.');
        }

        try {
            $data = json_decode((string) file_get_contents((string) $path), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('--user-map: не JSON.');
        }

        $map = [];

        foreach (is_array($data) ? $data : [] as $planfix => $plan) {
            if (! is_numeric($planfix) || ! is_numeric($plan)) {
                throw new RuntimeException('--user-map: очікується {"planfix id": id людини в Плані}.');
            }

            $map[(int) $planfix] = (int) $plan;
        }

        return $map;
    }

    /** Відповідність людей: явна (--user-map) і лише ПІДКАЗКИ за точним збігом імені. */
    private function people(SymfonyStyle $io, string $batch, array $userMap): void
    {
        $rows = [];

        foreach ($this->db->fetchAllAssociative('SELECT planfix_id, name, active FROM planfix_import_user WHERE batch_id = ? AND active ORDER BY planfix_id', [$batch]) as $u) {
            $pid = (int) $u['planfix_id'];
            $hint = '';

            if (! isset($userMap[$pid]) && trim((string) $u['name']) !== '') {
                $ids = $this->db->fetchFirstColumn(
                    "SELECT id FROM telegram_user WHERE archived_at IS NULL AND telegram_id NOT LIKE 'planfix-%'
                       AND (lower(trim(coalesce(first_name, '') || ' ' || coalesce(last_name, ''))) = lower(?)
                         OR lower(trim(coalesce(last_name, '') || ' ' || coalesce(first_name, ''))) = lower(?))",
                    [$u['name'], $u['name']],
                );
                $hint = $ids === [] ? '' : 'можливо #' . implode(', #', $ids) . ' (перевірити!)';
            }

            $rows[] = [$pid, $u['name'], isset($userMap[$pid]) ? '#' . $userMap[$pid] : 'заглушка', $hint];
        }

        $others = (int) $this->db->fetchOne('SELECT COUNT(*) FROM planfix_import_user WHERE batch_id = ? AND NOT active', [$batch]);
        $io->section('Люди');
        $io->table(['Planfix id', 'Імʼя (діючі в Planfix)', 'У Плані', 'Підказка'], $rows);
        $io->text(sprintf('Ще %d id без облікового запису в users.json (колишні) — «%s #<id>».', $others, PlanfixApplier::PLACEHOLDER_NAME));
    }

    private function samples(SymfonyStyle $io, string $batch, int $limit): void
    {
        if ($limit === 0) {
            return;
        }

        // Приклад: найбагатші на дії угода й задача, далі рівномірно по номерах.
        $busiest = 'SELECT t.planfix_id FROM planfix_import_task t WHERE t.batch_id = ? AND t.kind = ?
            ORDER BY (SELECT COUNT(*) FROM planfix_import_action a WHERE a.batch_id = t.batch_id AND a.task_planfix_id = t.planfix_id) DESC, t.planfix_id LIMIT 1';
        $ids = [$this->db->fetchOne($busiest, [$batch, PlanfixMapper::KIND_DEAL]), $this->db->fetchOne($busiest, [$batch, PlanfixMapper::KIND_TASK])];
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM planfix_import_task WHERE batch_id = ?', [$batch]);
        $rest = max(0, $limit - 2);

        for ($i = 1; $i <= $rest; ++$i) {
            $ids[] = $this->db->fetchOne('SELECT planfix_id FROM planfix_import_task WHERE batch_id = ? ORDER BY number OFFSET ? LIMIT 1', [$batch, intdiv($total * $i, $rest + 1)]);
        }

        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => $id !== false)));

        $io->section(sprintf('Приклади (%d)', min($limit, count($ids))));

        foreach (array_slice($ids, 0, $limit) as $pid) {
            $t = $this->db->fetchAssociative('SELECT * FROM planfix_import_task WHERE batch_id = ? AND planfix_id = ?', [$batch, $pid]);

            if ($t === false) {
                continue;
            }

            $messages = $this->db->fetchAllAssociative('SELECT at, owner_id, decision, text FROM planfix_import_action WHERE batch_id = ? AND task_planfix_id = ? ORDER BY at, planfix_id', [$batch, $pid]);
            $io->text(sprintf('<info>Planfix № %d</info> (%s) → «%s»', $t['number'], $t['kind'] === 'deal' ? 'угода' : 'задача', PlanfixMapper::oneLine((string) $t['title'], 80)));
            $io->listing([
                sprintf('статус: %s → %s%s', $t['status_raw'], $t['status'], $t['stage'] !== null ? ' + мітка «Етап: ' . $t['stage'] . '»' : ''),
                sprintf('створено %s, остання дія %s, строк %s', $t['created_at'], $t['last_at'], $t['due_at'] ?? '—'),
                sprintf('автор planfix#%d, учасники %s, відділи %s', $t['owner_id'], $t['member_ids'], $t['group_names']),
                'клієнт: ' . ($t['client'] ?? '—'),
                'опис: ' . PlanfixMapper::oneLine((string) $t['description'], 220),
                sprintf('повідомлень у чат: %d', count($messages)),
                ...array_map(static fn ($m) => sprintf('  %s planfix#%d %s: %s', $m['at'], $m['owner_id'], $m['decision'], PlanfixMapper::oneLine((string) $m['text'], 110)), array_slice($messages, 0, 3)),
            ]);
        }
    }
}
