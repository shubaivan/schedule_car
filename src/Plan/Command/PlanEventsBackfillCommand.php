<?php

namespace App\Plan\Command;

use App\Plan\Event\CommentAdded;
use App\Plan\Event\CommentDeleted;
use App\Plan\Event\CommentEdited;
use App\Plan\Event\FileAdded;
use App\Plan\Event\FileRemoved;
use App\Plan\Event\ItemCreated;
use App\Plan\Event\LoginFailed;
use App\Plan\Event\MemberAdded;
use App\Plan\Event\PlanEvent;
use App\Plan\Event\StatusEvent;
use App\Plan\Event\StockMoved;
use App\Plan\Event\SubjectCreated;
use App\Plan\Event\SubjectUpdated;
use App\Plan\Event\SubjectViewed;
use App\Plan\Event\UserLoggedIn;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Supply\Enum\SupplyStatus;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Enum\TaskStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Перенести історію модулів у журнал «Плану»:
 *
 *     php bin/console plan:events:backfill --dry-run
 *     php bin/console plan:events:backfill
 *
 * Джерела: task_message (+ правки task_message_edit і видалення), supply_status_log,
 * supply_comment, wh_activity, crm_login. Події лише пишуться (без розсилки —
 * старі зміни не шлють Telegram) з payload.backfill = true і каналом cli
 * (склад — канал із wh_activity, входи — web).
 *
 * Безпечно запускати повторно й після того, як журнал уже пишеться наживо:
 * - береться лише те, що старіше за першу «живу» подію (усе новіше вже записали сервіси);
 * - подія, що вже є в журналі (той самий тип, суб'єкт, refId), вдруге не пишеться.
 */
#[AsCommand(name: 'plan:events:backfill', description: 'Історія задач, заявок, складу й входів → журнал plan_event')]
final class PlanEventsBackfillCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly PlanEventRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише порахувати, що було б записано');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $cutoff = $this->db->fetchOne("SELECT MIN(occurred_at) FROM plan_event WHERE payload->>'backfill' IS NULL");
        $cutoff = $cutoff !== null && $cutoff !== false ? new DateTimeImmutable((string) $cutoff, self::zone()) : null;
        $known = $this->knownKeys();

        $events = [...$this->tasks(), ...$this->supply(), ...$this->warehouse(), ...$this->logins()];
        // Хронологічний порядок → id журналу йдуть так само, як події в житті.
        usort($events, static fn (PlanEvent $a, PlanEvent $b) => $a->at <=> $b->at);

        $counts = [];
        $skipped = 0;

        $write = function () use ($events, $cutoff, &$known, &$counts, &$skipped, $dryRun): void {
            foreach ($events as $event) {
                $key = self::key($event);

                if (($cutoff !== null && $event->at >= $cutoff) || isset($known[$key])) {
                    ++$skipped;
                    continue;
                }

                if (! $dryRun) {
                    $this->recorder->import($event, ['backfill' => true]);
                }

                $known[$key] = true;

                $counts[$event::type()] = ($counts[$event::type()] ?? 0) + 1;
            }
        };

        $dryRun ? $write() : $this->db->transactional($write);

        ksort($counts);
        $io->table(['Тип події', 'Записів'], array_map(null, array_keys($counts), array_values($counts)));
        $io->writeln(sprintf('Пропущено (уже в журналі або новіші за %s): %d', $cutoff?->format('Y-m-d H:i:s') ?? '—', $skipped));
        $io->success(($dryRun ? '[dry-run] Було б записано: ' : 'Записано: ') . array_sum($counts));

        return Command::SUCCESS;
    }

    /** @return list<PlanEvent> */
    private function tasks(): array
    {
        $out = [];
        $files = [];

        foreach ($this->db->fetchAllAssociative('SELECT id, message_id, original_name FROM task_attachment ORDER BY id') as $f) {
            $files[(int) $f['message_id']][] = ['id' => (int) $f['id'], 'name' => (string) $f['original_name']];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT m.id, m.task_id, m.author_id, m.kind, m.event_type, m.text, m.mentions, m.created_at, m.deleted_at, t.title
               FROM task_message m JOIN task t ON t.id = m.task_id ORDER BY m.id',
        );

        foreach ($rows as $r) {
            $ref = SubjectRef::task((int) $r['task_id']);
            $actor = self::int($r['author_id']);
            $at = self::at($r['created_at']);
            $id = (int) $r['id'];

            if ($r['kind'] === TaskMessage::KIND_EVENT) {
                $out[] = match ($r['event_type']) {
                    TaskMessage::EVENT_CREATED => new SubjectCreated($ref, $actor, title: (string) $r['title'], refId: $id, at: $at),
                    TaskMessage::EVENT_STATUS, TaskMessage::EVENT_CLOSED => $this->taskStatus($ref, $actor, (string) $r['text'], $id, $at),
                    TaskMessage::EVENT_MEMBERS => new MemberAdded($ref, $actor, refId: $id, at: $at),
                    default => new SubjectUpdated($ref, $actor, summary: (string) $r['text'], refId: $id, at: $at),
                };
            } else {
                $attached = $files[$id] ?? [];
                $text = (string) $r['text'];
                $out[] = $text === '' && $attached !== []
                    ? new FileAdded($ref, $actor, fileIds: array_column($attached, 'id'), names: array_column($attached, 'name'), refId: $id, at: $at)
                    : new CommentAdded($ref, $actor, refId: $id, excerpt: mb_substr($text, 0, 200), mentions: array_values(array_map('intval', (array) json_decode((string) $r['mentions'], true))), files: count($attached), at: $at);

                if ($r['deleted_at'] !== null) {
                    $out[] = new CommentDeleted($ref, $actor, refId: $id, at: self::at($r['deleted_at']));
                }
            }
        }

        foreach ($this->db->fetchAllAssociative(
            'SELECT e.message_id, e.editor_id, e.edited_at, m.task_id, m.text FROM task_message_edit e JOIN task_message m ON m.id = e.message_id ORDER BY e.id',
        ) as $e) {
            $out[] = new CommentEdited(SubjectRef::task((int) $e['task_id']), self::int($e['editor_id']), refId: (int) $e['message_id'], at: self::at($e['edited_at']));
        }

        return $out;
    }

    /** «змінив(ла) статус: В роботі → Виконана» → значення статусів. */
    private function taskStatus(SubjectRef $ref, ?int $actor, string $text, int $id, DateTimeImmutable $at): PlanEvent
    {
        $labels = [];

        foreach (TaskStatus::cases() as $status) {
            $labels[$status->label()] = $status;
        }

        $from = $to = null;

        if (preg_match('/статус: (.+) → (.+)$/u', $text, $m) === 1) {
            $from = $labels[trim($m[1])] ?? null;
            $to = $labels[trim($m[2])] ?? null;
        }

        if ($to === null) {
            return new SubjectUpdated($ref, $actor, fields: ['status'], summary: $text, refId: $id, at: $at);
        }

        return StatusEvent::for($ref, $actor, $from?->value, $to->value, closes: $to->isClosed(), asksApproval: $to === TaskStatus::Approval, refId: $id, at: $at);
    }

    /** @return list<PlanEvent> */
    private function supply(): array
    {
        $out = [];

        foreach ($this->db->fetchAllAssociative(
            'SELECT l.id, l.request_id, l.author_id, l.status_from, l.status_to, l.comment, l.created_at, r.number, r.item
               FROM supply_status_log l JOIN supply_request r ON r.id = l.request_id ORDER BY l.id',
        ) as $r) {
            $ref = SubjectRef::supply((int) $r['request_id']);
            $to = SupplyStatus::tryFrom((string) $r['status_to']);

            if ($r['status_from'] === null) {
                $out[] = new SubjectCreated($ref, self::int($r['author_id']), title: sprintf('№ %s · %s', $r['number'], $r['item']), refId: (int) $r['id'], at: self::at($r['created_at']));
                continue;
            }

            $out[] = StatusEvent::for(
                $ref,
                self::int($r['author_id']),
                (string) $r['status_from'],
                (string) $r['status_to'],
                closes: $to !== null && ($to->isFinal() || $to === SupplyStatus::Rejected),
                asksApproval: $to === SupplyStatus::Approval,
                comment: $r['comment'] !== null ? (string) $r['comment'] : null,
                refId: (int) $r['id'],
                at: self::at($r['created_at']),
            );
        }

        foreach ($this->db->fetchAllAssociative('SELECT id, request_id, author_id, text, created_at FROM supply_comment ORDER BY id') as $c) {
            $out[] = new CommentAdded(SubjectRef::supply((int) $c['request_id']), self::int($c['author_id']), refId: (int) $c['id'], excerpt: mb_substr((string) $c['text'], 0, 200), at: self::at($c['created_at']));
        }

        return $out;
    }

    /** @return list<PlanEvent> */
    private function warehouse(): array
    {
        $out = [];
        $lines = [];

        foreach ($this->db->fetchAllAssociative('SELECT movement_id, item_id FROM wh_movement_line ORDER BY id') as $l) {
            $lines[(int) $l['movement_id']][] = (int) $l['item_id'];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT a.id, a.user_id, a.action, a.subject_type, a.subject_id, a.subject_label, a.channel, a.details, a.at,
                    mv.type AS mv_type, mv.from_site_id, mv.to_site_id
               FROM wh_activity a
               LEFT JOIN wh_movement mv ON a.subject_type = \'movement\' AND mv.id = a.subject_id
              ORDER BY a.id',
        );

        foreach ($rows as $r) {
            $ref = SubjectRef::of(SubjectType::fromWarehouse((string) $r['subject_type']), (int) $r['subject_id']);
            $actor = self::int($r['user_id']);
            $at = self::at($r['at']);
            $id = (int) $r['id'];
            $details = $r['details'] !== null ? (string) $r['details'] : null;
            $label = (string) $r['subject_label'];

            $event = match ((string) $r['action']) {
                'create' => $ref->type === SubjectType::WhItem
                    ? new ItemCreated($ref, $actor, title: $label, refId: $id, at: $at)
                    : new SubjectCreated($ref, $actor, title: $label, refId: $id, at: $at),
                'update' => new SubjectUpdated($ref, $actor, summary: $details, refId: $id, at: $at),
                // Рух — одна подія на рух; рядки «рух» на кожній позиції — частина тієї ж дії.
                'move' => $ref->type === SubjectType::WhMovement && $r['mv_type'] !== null
                    ? new StockMoved($ref, $actor, movementType: (string) $r['mv_type'], title: $label, fromSiteId: self::int($r['from_site_id']), toSiteId: self::int($r['to_site_id']), itemIds: $lines[$ref->id] ?? [], at: $at)
                    : null,
                'doc_add' => new FileAdded($ref, $actor, names: [(string) $details], refId: $id, at: $at),
                'doc_remove' => new FileRemoved($ref, $actor, names: [(string) $details], at: $at),
                default => new SubjectViewed($ref, $actor, action: (string) $r['action'], details: $details, refId: $id, at: $at),
            };

            if ($event !== null) {
                $out[] = $event->via($r['channel'] === 'bot' ? 'bot' : 'web');
            }
        }

        return $out;
    }

    /** @return list<PlanEvent> */
    private function logins(): array
    {
        $out = [];

        foreach ($this->db->fetchAllAssociative('SELECT id, user_id, name, success, reason, ip, user_agent, at FROM crm_login ORDER BY id') as $r) {
            $user = self::int($r['user_id']);
            $at = self::at($r['at']);
            $agent = $r['user_agent'] !== null ? (string) $r['user_agent'] : null;
            $ip = $r['ip'] !== null ? (string) $r['ip'] : null;

            $event = $r['success'] && $user !== null
                ? new UserLoggedIn(SubjectRef::user($user), $user, userAgent: $agent, refId: (int) $r['id'], at: $at)
                : new LoginFailed($user !== null ? SubjectRef::user($user) : SubjectRef::unknownUser(), null, name: $r['name'] !== null ? (string) $r['name'] : null, reason: $r['reason'] !== null ? (string) $r['reason'] : null, userAgent: $agent, refId: (int) $r['id'], at: $at);

            $out[] = $event->via('web', $ip);
        }

        return $out;
    }

    /**
     * Ключі подій, що вже є в журналі. Правки й видалення коментаря — ще й за
     * часом: один коментар правлять кілька разів.
     *
     * @return array<string, true>
     */
    private function knownKeys(): array
    {
        $known = [];

        foreach ($this->db->fetchAllAssociative(
            "SELECT type, subject_type, subject_id, payload->>'refId' AS ref, occurred_at FROM plan_event",
        ) as $r) {
            $known[implode('|', [$r['type'], $r['subject_type'], $r['subject_id'], $r['ref'] ?? '', in_array($r['type'], [CommentEdited::TYPE, FileRemoved::TYPE], true) || $r['ref'] === null ? (string) $r['occurred_at'] : ''])] = true;
        }

        return $known;
    }

    private static function key(PlanEvent $event): string
    {
        $ref = $event->payload()['refId'] ?? null;

        return implode('|', [
            $event::type(),
            $event->subject->type->value,
            $event->subject->id,
            $ref ?? '',
            $event instanceof CommentEdited || $event instanceof FileRemoved || $ref === null ? $event->at->format('Y-m-d H:i:s') : '',
        ]);
    }

    private static function at(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $value, self::zone());
    }

    private static function int(mixed $value): ?int
    {
        return $value !== null ? (int) $value : null;
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Kyiv');
    }
}
