<?php

namespace App\Tasks\Listener;

use App\Entity\TelegramUser;
use App\Plan\Event\CommentAdded;
use App\Plan\Event\FileAdded;
use App\Plan\Event\MemberAdded;
use App\Plan\Event\PlanEvent;
use App\Plan\Event\StatusEvent;
use App\Plan\Event\SubjectCreated;
use App\Plan\EventStore\PlanEventConsumer;
use App\Plan\Subject\SubjectType;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Service\TaskNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Telegram по задачах — з подій журналу. ЄДИНЕ місце, звідки події задачі
 * доходять до TaskNotifier (страж — NoDoubleNotificationTest).
 *
 * Правка/видалення коментаря, зміна полів без нових учасників — без Telegram,
 * як і до журналу.
 */
final class TaskNotificationListener implements PlanEventConsumer
{
    public function __construct(
        private readonly TaskNotifier $notifier,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function consume(PlanEvent $event): void
    {
        if ($event->subject->type !== SubjectType::Task) {
            return;
        }

        $task = $this->em->find(Task::class, $event->subject->id);

        if ($task === null) {
            $this->logger->info('tasks: сповіщення пропущено — задачі вже немає', ['event' => $event->id()]);

            return;
        }

        match (true) {
            $event instanceof SubjectCreated => $this->notifier->created($task),
            $event instanceof MemberAdded => $this->added($event, $task),
            $event instanceof StatusEvent => $this->status($event, $task),
            $event instanceof CommentAdded, $event instanceof FileAdded => $this->message($event),
            default => null,
        };
    }

    private function added(MemberAdded $event, Task $task): void
    {
        $by = $this->actor($event);
        $people = $event->userIds === [] ? [] : $this->em->getRepository(TelegramUser::class)->findBy(['id' => $event->userIds]);

        if ($by !== null && $people !== []) {
            $this->notifier->added($task, $people, $by);
        }
    }

    private function status(StatusEvent $event, Task $task): void
    {
        $by = $this->actor($event);
        $from = $event->from !== null ? TaskStatus::tryFrom($event->from) : null;

        if ($by !== null && $from !== null) {
            $this->notifier->statusChanged($task, $from, $by, $event->comment, TaskStatus::tryFrom($event->to));
        }
    }

    private function message(CommentAdded|FileAdded $event): void
    {
        $message = $event->refId !== null ? $this->em->find(TaskMessage::class, $event->refId) : null;

        if ($message !== null && $message->getDeletedAt() === null) {
            $this->notifier->message($message);
        }
    }

    private function actor(PlanEvent $event): ?TelegramUser
    {
        return $event->actorId !== null ? $this->em->find(TelegramUser::class, $event->actorId) : null;
    }
}
