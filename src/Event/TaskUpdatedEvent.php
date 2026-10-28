<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\Task;

use function in_array;

/**
 * Fields of a task were edited — {@see TaskBoardEvents::TASK_UPDATED}. Only dispatched when at least one field
 * changed.
 */
final class TaskUpdatedEvent extends AbstractTaskEvent
{
    /**
     * @param list<string> $changedFields Names of the changed fields: title, description, priority, column,
     *                                    estimatedMinutes, dueAt, tags
     */
    public function __construct(
        Task $task,
        object $actor,
        private readonly array $changedFields,
    ) {
        parent::__construct($task, $actor);
    }

    /**
     * @return list<string>
     */
    public function getChangedFields(): array
    {
        return $this->changedFields;
    }

    public function hasChanged(string $field): bool
    {
        return in_array($field, $this->changedFields, true);
    }
}
