<?php

namespace App\Warehouse\Api;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanAccess;
use App\Plan\Access\PlanCapability;
use App\Tasks\Repository\TaskVisibility;
use App\Tasks\Service\TasksSection;
use App\Warehouse\Entity\WhItem;
use Doctrine\DBAL\Connection;

/**
 * Де згадано позицію складу: задачі й заявки «Плану», у назві чи описі яких
 * є її інвентарний номер або марка виробу з назви («КС 10-9», «ПК 60-12»,
 * «ФБС 24-4-6», «М500»). Окремої таблиці зв'язків немає — і не треба:
 * менеджер пише в задачі «кільця КС 10-9», і картка кільця сама знаходить задачу.
 *
 * Права ті самі, що всюди: задачі — за TaskVisibility (виконавець не побачить
 * чужих), заявки — тим, хто веде постачання (склад і так ведуть ті самі люди).
 */
final class ItemMentions
{
    private const int LIMIT = 8;

    public function __construct(
        private readonly Connection $connection,
        private readonly TasksSection $tasks,
        private readonly PlanAccess $access,
    ) {
    }

    /**
     * Марки з назви: літери + цифри з роздільниками («КС 10.9» → «кс10-9»).
     * Інвентарний номер — завжди перший.
     *
     * @return list<string>
     */
    public static function codes(WhItem $item): array
    {
        $codes = [self::normalize($item->getInventoryNumber())];

        preg_match_all('/(?<![\p{L}\d])\p{Lu}{1,4}\s?\d+(?:[.\-]\d+)*\p{Lu}?(?![\p{L}\d])/u', $item->getName(), $m);

        foreach ($m[0] as $code) {
            $code = self::normalize($code);

            if (mb_strlen($code) >= 3) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique(array_filter($codes, static fn (string $c) => $c !== '')));
    }

    /** Нижній регістр, крапка як дефіс, без пробілів — так «КС 10.9» і «кс10-9» однакові. */
    public static function normalize(string $text): string
    {
        return str_replace([' ', '.'], ['', '-'], mb_strtolower(trim($text)));
    }

    /** @return array{tasks: list<array<string, mixed>>, supply: list<array<string, mixed>>} */
    public function of(WhItem $item, TelegramUser $viewer): array
    {
        $codes = self::codes($item);

        return [
            'tasks' => $this->tasks->enabled() ? $this->taskMentions($codes, $viewer) : [],
            'supply' => $this->access->can($viewer, PlanCapability::SupplyManage) ? $this->supplyMentions($codes) : [],
        ];
    }

    /**
     * @param list<string> $codes
     *
     * @return list<array<string, mixed>>
     */
    private function taskMentions(array $codes, TelegramUser $viewer): array
    {
        [$visible, $params] = TaskVisibility::sql($viewer, 't');
        [$match, $codeParams] = $this->match("t.title || ' ' || COALESCE(t.description, '') || ' ' || COALESCE(t.client, '')", $codes);

        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT t.id, t.title, t.status, t.closed_at, t.last_activity_at FROM task t WHERE %s AND %s ORDER BY t.last_activity_at DESC NULLS LAST, t.id DESC LIMIT %d', $visible, $match, self::LIMIT),
            [...$params, ...$codeParams],
        );

        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'status' => (string) $r['status'],
            'closed' => $r['closed_at'] !== null,
        ], $rows);
    }

    /**
     * @param list<string> $codes
     *
     * @return list<array<string, mixed>>
     */
    private function supplyMentions(array $codes): array
    {
        [$match, $params] = $this->match("r.item || ' ' || COALESCE(r.note, '') || ' ' || COALESCE(r.site, '')", $codes);

        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT r.id, r.number, r.item, r.status FROM supply_request r WHERE %s ORDER BY r.id DESC LIMIT %d', $match, self::LIMIT),
            $params,
        );

        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'number' => (string) $r['number'],
            'title' => (string) $r['item'],
            'status' => (string) $r['status'],
        ], $rows);
    }

    /**
     * @param list<string> $codes
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function match(string $column, array $codes): array
    {
        $normalized = sprintf("replace(replace(lower(%s), ' ', ''), '.', '-')", $column);
        $parts = [];
        $params = [];

        foreach ($codes as $i => $code) {
            $parts[] = sprintf('%s LIKE :code%d', $normalized, $i);
            $params['code' . $i] = '%' . addcslashes($code, '%_\\') . '%';
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
    }
}
