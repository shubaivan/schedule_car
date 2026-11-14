<?php

namespace App\Plan\Subject\Provider;

use App\Entity\TelegramUser;
use App\Plan\Subject\SubjectProvider;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Tasks\Entity\Task;
use App\Tasks\Service\TaskPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Задачі: «в темі» — автор, учасники й ті, хто позначив задачу ★ (спостерігачі). */
final class TaskSubjects implements SubjectProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaskPolicy $policy,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function supports(SubjectType $type): bool
    {
        return $type === SubjectType::Task;
    }

    public function title(SubjectRef $ref): ?string
    {
        $task = $this->em->find(Task::class, $ref->id);

        return $task !== null ? sprintf('№ %s · %s', $task->number(), $task->getTitle()) : null;
    }

    public function url(SubjectRef $ref): string
    {
        return $this->urls->generate('crm_index', ['path' => 'tasks/board/' . $ref->id]);
    }

    public function audience(SubjectRef $ref): array
    {
        $task = $this->em->find(Task::class, $ref->id);

        if ($task === null) {
            return [];
        }

        $ids = [(int) $task->getCreator()->getId()];

        foreach ($task->getMembers() as $member) {
            $ids[] = (int) $member->getUser()->getId();
        }

        foreach ($this->em->getConnection()->fetchFirstColumn('SELECT user_id FROM task_favorite WHERE task_id = ?', [$ref->id]) as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    public function visibleTo(SubjectRef $ref, TelegramUser $user): bool
    {
        $task = $this->em->find(Task::class, $ref->id);

        return $task !== null && $this->policy->canView($user, $task);
    }
}
