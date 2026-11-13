<?php

namespace App\Tasks\Repository;

use App\Tasks\Entity\TaskLabel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TaskLabel> */
class TaskLabelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskLabel::class);
    }

    /** @return TaskLabel[] */
    public function ordered(): array
    {
        return $this->findBy([], ['sort' => 'ASC', 'id' => 'ASC']);
    }
}
