<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;

use function array_values;

final readonly class DoctrineOrmTaskDependencyRepository implements TaskDependencyRepositoryInterface, TaskDependentsLookupInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Expose the manager for future persistence methods (keeps DI wiring valid).
     */
    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    /**
     * Since 2.16.0 (spec 234): one query by the task's id; only tasks of the task's tenant (compared byte for byte in
     * PHP, REQ-SEC-006), so a relation that crossed tenants never answers.
     */
    public function dependentsOf(Task $task): array
    {
        /** @var list<TaskDependency> $dependencies */
        $dependencies = $this->entityManager->createQueryBuilder()
            ->select('d', 's', 't', 'sb', 'tb')
            ->from(TaskDependency::class, 'd')
            ->join('d.sourceTask', 's')
            ->join('d.targetTask', 't')
            ->join('s.board', 'sb')
            ->join('t.board', 'tb')
            ->where('(d.targetTask = :task AND d.dependencyType = :blockedBy) OR (d.sourceTask = :task AND d.dependencyType = :blocks)')
            ->setParameter('task', $task->getId())
            ->setParameter('blockedBy', TaskDependencyType::BlockedBy)
            ->setParameter('blocks', TaskDependencyType::Blocks)
            ->getQuery()
            ->getResult();

        $tenant     = $task->getBoard()->getTenantKey();
        $dependents = [];
        foreach ($dependencies as $dependency) {
            $other = $dependency->getTargetTask()->getId() === $task->getId() ? $dependency->getSourceTask() : $dependency->getTargetTask();
            if ($other->getId() !== $task->getId() && $other->getBoard()->getTenantKey() === $tenant) {
                $dependents[$other->getId()] = $other;
            }
        }

        return array_values($dependents);
    }
}
