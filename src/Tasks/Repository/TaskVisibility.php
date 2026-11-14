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
