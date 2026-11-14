<?php

namespace App\Warehouse\Api;

use App\Entity\TelegramUser;
use App\Plan\Event\FileAdded;
use App\Plan\Event\FileRemoved;
use App\Plan\Event\ItemCreated;
use App\Plan\Event\StockMoved;
use App\Plan\Event\SubjectCreated;
use App\Plan\Event\SubjectUpdated;
use App\Plan\Event\SubjectViewed;
use App\Plan\Subject\SubjectType;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\MovementType;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Стрічка позиції складу — з журналу подій «Плану» (plan_event), у тому самому
 * форматі повідомлень, що й стрічка задачі чи заявки: її малює той самий TaskChat
 * (лише події, без поля вводу — коментарів у позиції складу немає).
 *
 * Що потрапляє: створення, зміни картки, кожен рух із цією позицією (StockMoved
 * пишеться на рух, а позиції — у payload.itemIds), документи, скани QR, переходи
 * за посиланням із бота й друк наклейок. Звичайне «відкрив картку» — ні: це
 * аудит (журнал складу), а не історія речі, і в стрічці воно лише заважало б.
 */
final class ItemFeed
{
    private const LIMIT = 200;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly WarehousePresenter $presenter,
    ) {
    }

    /** @return array<string, mixed> FeedSubject для TaskChat */
    public function feed(WhItem $item, TelegramUser $viewer): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT * FROM (
                    SELECT id, type, subject_type, subject_id, actor_id, occurred_at, payload
                    FROM plan_event
                    WHERE (subject_type = :item_type AND subject_id = :id)
                       OR (type = :moved AND subject_type = :movement_type AND payload->'itemIds' @> CAST(:ids AS jsonb))
                    ORDER BY id DESC
                    LIMIT :limit
                ) last ORDER BY id
                SQL,
            [
                'item_type' => SubjectType::WhItem->value,
                'movement_type' => SubjectType::WhMovement->value,
                'moved' => StockMoved::TYPE,
                'id' => $item->getId(),
                'ids' => json_encode([(int) $item->getId()]),
                'limit' => self::LIMIT,
            ],
        );

        $movements = $this->movements($rows);
        $actors = $this->actors($rows);
        $messages = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $row['payload'], true) ?: [];
            [$eventType, $text] = $this->describe((string) $row['type'], $payload, $item, $movements[(int) $row['subject_id']] ?? null, $row);

            if ($text === null) {
                continue;
            }

            $actor = $actors[(int) $row['actor_id']] ?? null;
            $messages[] = [
                'id' => (int) $row['id'],
                'kind' => 'event',
                'eventType' => $eventType,
                'author' => $this->presenter->person($actor),
                'text' => $text,
                'mentions' => [],
                'createdAt' => (new DateTimeImmutable((string) $row['occurred_at']))->format(DateTimeInterface::ATOM),
                'deleted' => false,
                'mine' => $actor !== null && $actor->getId() === $viewer->getId(),
                'attachments' => [],
                'replyTo' => null,
                'editedAt' => null,
                'pinned' => null,
                'starred' => false,
                'can' => ['edit' => false, 'delete' => false],
            ];
        }

        return [
            'id' => $item->getId(),
            'status' => $item->getState()->value,
            'messages' => $messages,
            'files' => [],
            'reads' => [],
            'readUpTo' => null,
            'now' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'can' => ['comment' => false, 'attach' => false],
        ];
    }

    /**
     * Людський текст події. null — події в стрічці позиції не показуємо.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $row
     *
     * @return array{0: string, 1: ?string}
     */
    private function describe(string $type, array $payload, WhItem $item, ?WhMovement $movement, array $row): array
    {
        $isMovement = $row['subject_type'] === SubjectType::WhMovement->value;

        return match ($type) {
            ItemCreated::TYPE, SubjectCreated::TYPE => ['created', $isMovement ? null : 'створив позицію'],
            SubjectUpdated::TYPE => ['updated', 'змінив картку' . (($payload['summary'] ?? '') !== '' ? ': ' . $payload['summary'] : '')],
            StockMoved::TYPE => ['status', $this->moved($payload, $item, $movement)],
            FileAdded::TYPE => ['file', 'додав документ: ' . implode(', ', (array) ($payload['names'] ?? []))],
            FileRemoved::TYPE => ['file', 'прибрав документ: ' . implode(', ', (array) ($payload['names'] ?? []))],
            SubjectViewed::TYPE => ['updated', match ($payload['action'] ?? '') {
                ActivityAction::Scan->value => '📷 сканував QR-наклейку' . (($payload['details'] ?? '') !== '' ? ' (' . $payload['details'] . ')' : ''),
                ActivityAction::Link->value => '🔗 перейшов за посиланням' . (($payload['details'] ?? '') !== '' ? ' ' . $payload['details'] : ''),
                ActivityAction::Labels->value => '🖨 друкував наклейки' . (($payload['details'] ?? '') !== '' ? ' · ' . $payload['details'] : ''),
                default => null,
            }],
            default => ['updated', null],
        };
    }

    /** @param array<string, mixed> $payload */
    private function moved(array $payload, WhItem $item, ?WhMovement $movement): string
    {
        $type = MovementType::tryFrom((string) ($payload['movementType'] ?? ''));

        if ($movement === null) {
            // Рух прибрали — лишаємо те, що записано в самій події.
            return sprintf('%s %s', $type?->emoji() ?? '🔁', (string) ($payload['title'] ?? 'рух'));
        }

        $quantity = null;

        foreach ($movement->getLines() as $line) {
            if ($line->getItem()->getId() === $item->getId()) {
                $quantity = $line->getQuantity();
            }
        }

        return sprintf(
            '%s %s: %s%s%s',
            $movement->getType()->emoji(),
            $movement->getTitle(),
            WarehousePresenter::route($movement),
            $quantity !== null ? sprintf(', %d %s', $quantity, $item->getUnit()) : '',
            $movement->getClient() !== null ? ' · ' . $movement->getClient()->getName() : '',
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, WhMovement>
     */
    private function movements(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if ($row['subject_type'] === SubjectType::WhMovement->value) {
                $ids[] = (int) $row['subject_id'];
            }
        }

        if ($ids === []) {
            return [];
        }

        $out = [];

        /** @var WhMovement[] $found */
        $found = $this->em->createQueryBuilder()
            ->select('m', 'l', 'i', 'f', 't')
            ->from(WhMovement::class, 'm')
            ->leftJoin('m.lines', 'l')
            ->leftJoin('l.item', 'i')
            ->leftJoin('m.fromSite', 'f')
            ->leftJoin('m.toSite', 't')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', array_values(array_unique($ids)), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        foreach ($found as $movement) {
            $out[(int) $movement->getId()] = $movement;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, TelegramUser>
     */
    private function actors(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $r) => (int) $r['actor_id'], $rows))));

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach ($this->em->getRepository(TelegramUser::class)->findBy(['id' => $ids]) as $user) {
            $out[(int) $user->getId()] = $user;
        }

        return $out;
    }
}
