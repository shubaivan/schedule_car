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
        return $user->getWorkRole()->seesAllTasks() || $this->isInvolved($user, $task);
    }

    public function canEdit(TelegramUser $user, Task $task): bool
    {
        return $user->getWorkRole()->editsAnyTask() ||
            $user->getWorkRole()->decides() ||
            $task->getCreator()->getId() === $user->getId();
    }

    public function canComment(TelegramUser $user, Task $task): bool
    {
        return $this->canView($user, $task);
    }

    /**
     * Рішення про гроші — лише директор (і адмін як запасний ключ): поки задача
     * «На погодженні» чи «Очікує», і коли перехід сам є рішенням.
     * Решту руху веде той, хто працює із задачею: менеджер, автор чи учасник.
     */
    public function canMove(TelegramUser $user, Task $task, TaskStatus $to): bool
    {
        $from = $task->getStatus();

        if (! $from->canMoveTo($to) || ! $this->canView($user, $task)) {
            return false;
        }

        if ($from->awaitsDecision() || $to->isDecision()) {
            return $user->getWorkRole()->decides();
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

        return $message->getAuthor()?->getId() === $user->getId() || $user->getWorkRole()->managesStaff();
    }

    public function isInvolved(TelegramUser $user, Task $task): bool
    {
        return $task->getCreator()->getId() === $user->getId() || $task->hasMember($user);
    }
}
