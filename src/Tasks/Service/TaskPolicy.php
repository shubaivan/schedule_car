<?php

namespace App\Tasks\Service;

use App\Entity\TelegramUser;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Enum\TaskStatus;

/**
 * Одне місце правди про права в задачах: його питають і сервіси (гвард),
 * і презентер (які кнопки показати). Якщо кнопку видно — натискання пройде.
 */
class TaskPolicy
{
    public function canView(TelegramUser $user, Task $task): bool
    {
        // Роль без «Задачі й угоди» задач не бачить узагалі — навіть де учасник; системний адмін — як звичайний користувач.
        return $user->canSeeTasks() && ($user->seesAllTasks() || $this->isInvolved($user, $task));
    }

    public function canEdit(TelegramUser $user, Task $task): bool
    {
        if (! $user->canSeeTasks()) {
            return false;
        }

        return $user->getWorkRole()->editsAnyTask() ||
            $user->getWorkRole()->decides() ||
            $task->getCreator()->getId() === $user->getId();
    }

    public function canComment(TelegramUser $user, Task $task): bool
    {
        return $this->canView($user, $task);
    }

    /**
     * Статус веде той, хто працює із задачею: менеджер, директор, автор чи учасник.
     * Окремого «рішення директора» в задачах немає — питання вирішуються в чаті.
     */
    public function canMove(TelegramUser $user, Task $task, TaskStatus $to): bool
    {
        if (! $task->getStatus()->canMoveTo($to) || ! $this->canView($user, $task)) {
            return false;
        }

        return $user->getWorkRole()->editsAnyTask() ||
            $user->getWorkRole()->decides() ||
            $this->isInvolved($user, $task);
    }

    /** @return list<TaskStatus> */
    public function transitions(TelegramUser $user, Task $task): array
    {
        return array_values(array_filter(
            $task->getStatus()->allowedTransitions(),
            fn (TaskStatus $to) => $this->canMove($user, $task, $to),
        ));
    }

    /** Змінити повідомлення може лише його автор (і лише коментар, не системну подію). */
    public function canEditMessage(TelegramUser $user, TaskMessage $message): bool
    {
        return ! $message->isEvent() &&
            $message->getDeletedAt() === null &&
            $message->getAuthor()?->getId() === $user->getId() &&
            $this->canComment($user, $message->getTask());
    }

    /** Видалити — автор або адміністратор («видалити для всіх» за правами). */
    public function canDeleteMessage(TelegramUser $user, TaskMessage $message): bool
    {
        if ($message->isEvent() || $message->getDeletedAt() !== null || ! $this->canView($user, $message->getTask())) {
            return false;
        }

        // Чуже — адміністратор, який бачить усі задачі (не системний адмін: у своїх задачах він — як усі).
        return $message->getAuthor()?->getId() === $user->getId() || ($user->getWorkRole()->managesStaff() && $user->seesAllTasks());
    }

    public function isInvolved(TelegramUser $user, Task $task): bool
    {
        return $task->getCreator()->getId() === $user->getId() || $task->hasMember($user);
    }
}
