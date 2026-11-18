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
     * Задачі, де людина бере участь (автор, учасник, писала в чаті) або які позначила ★, — по них
     * рахуються «+N» і лічильник меню (для директора теж: інакше це шум усього заводу).
     * Рядки Хроніки — chronicle(). ★ працює тільки в межах видимості.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    public static function involved(TelegramUser $viewer, string $alias = 't'): array
    {
        [$visible, $params] = self::sql($viewer, $alias);

        return [
            sprintf(
                '(%2$s AND (%1$s.creator_id = :involved OR EXISTS (SELECT 1 FROM task_member im WHERE im.task_id = %1$s.id AND im.user_id = :involved)
                    OR EXISTS (SELECT 1 FROM task_favorite iv WHERE iv.task_id = %1$s.id AND iv.user_id = :involved)
                    OR EXISTS (SELECT 1 FROM task_message iw WHERE iw.task_id = %1$s.id AND iw.author_id = :involved AND iw.kind = \'comment\' AND iw.deleted_at IS NULL)))',
                $alias,
                $visible,
            ),
            [...$params, 'involved' => (int) $viewer->getId()],
        ];
    }

    /**
     * Які задачі є рядками Хроніки. Директор і адмін бачать УСЕ (рішення Івана
     * 14.11: директор має бачити весь завод), решта — лише задачі, де беруть
     * участь, або ★. «+N» і лічильник меню при цьому лишаються за involved():
     * на чужих задачах директора нових подій «для нього» немає.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    public static function chronicle(TelegramUser $viewer, string $alias = 't'): array
    {
        return $viewer->seesAllTasks() ? self::sql($viewer, $alias) : self::involved($viewer, $alias);
    }

    /**
     * @return array{0: string, 1: array<string, int>} умова для псевдоніма задачі та її параметри
     */
    public static function sql(TelegramUser $viewer, string $alias = 't'): array
    {
        // Роль без «Задачі й угоди» — жодної задачі (Хроніка, пошук, Огляд, лічильники).
        if (! $viewer->canSeeTasks()) {
            return ['FALSE', []];
        }

        if ($viewer->seesAllTasks()) {
            return ['TRUE', []];
        }

        return [
            sprintf('(%1$s.creator_id = :viewer OR EXISTS (SELECT 1 FROM task_member vm WHERE vm.task_id = %1$s.id AND vm.user_id = :viewer))', $alias),
            ['viewer' => (int) $viewer->getId()],
        ];
    }
}
