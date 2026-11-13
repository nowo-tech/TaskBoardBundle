<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;

use function array_values;

/**
 * Since 2.17.0: every task in a column (archived ones included), read from the database when a manager is given (the
 * column's own collection is the inverse side and misses tasks put in the column in the same request; the callers work
 * on saved tasks). Stateless.
 *
 * @internal
 */
final class ColumnTasks
{
    /**
     * @return list<Task>
     */
    public static function of(?EntityManagerInterface $entityManager, BoardColumn $column): array
    {
        if (!$entityManager instanceof EntityManagerInterface || !$entityManager->isOpen()) {
            return array_values($column->getTasks()->toArray());
        }
        /** @var list<Task> $tasks */
        $tasks = $entityManager->createQueryBuilder()->select('t')->from(Task::class, 't')
            ->where('IDENTITY(t.column) = :column')->setParameter('column', $column->getId())
            ->orderBy('t.position')->getQuery()->getResult();

        return $tasks;
    }
}
