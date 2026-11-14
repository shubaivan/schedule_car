<?php

namespace App\Tasks\Repository;

use App\Entity\TelegramUser;

/**
 * Те саме правило видимості, що й у TaskRepository::visibleTo() та TaskPolicy::canView(),
 * але шматком SQL — для сирих запитів Хроніки й пошуку: директор і адмін бачать
 * усе, решта — задачі, де вони автор або учасник.
 */
final class TaskVisibility
{
    /**
     * Хроніка — як у ПланФіксі: лише задачі, де людина бере участь (автор, учасник)
     * або які позначила ★. Навіть директорові — інакше це потік усього заводу.
     * ★ працює тільки в межах видимості: прибрали з задачі — зникла й з Хроніки.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    public static function involved(TelegramUser $viewer, string $alias = 't'): array
    {
        [$visible, $params] = self::sql($viewer, $alias);

        return [
            sprintf(
                '(%2$s AND (%1$s.creator_id = :involved OR EXISTS (SELECT 1 FROM task_member im WHERE im.task_id = %1$s.id AND im.user_id = :involved)
                    OR EXISTS (SELECT 1 FROM task_favorite iv WHERE iv.task_id = %1$s.id AND iv.user_id = :involved)))',
                $alias,
                $visible,
            ),
            [...$params, 'involved' => (int) $viewer->getId()],
        ];
    }

    /**
     * @return array{0: string, 1: array<string, int>} умова для псевдоніма задачі та її параметри
     */
    public static function sql(TelegramUser $viewer, string $alias = 't'): array
    {
        if ($viewer->getWorkRole()->seesAllTasks()) {
            return ['TRUE', []];
        }

        return [
            sprintf('(%1$s.creator_id = :viewer OR EXISTS (SELECT 1 FROM task_member vm WHERE vm.task_id = %1$s.id AND vm.user_id = :viewer))', $alias),
            ['viewer' => (int) $viewer->getId()],
        ];
    }
}
