<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\Task;

use function array_diff;
use function array_values;
use function in_array;
use function is_array;

/**
 * Fields of a task were edited — {@see TaskBoardEvents::TASK_UPDATED}. Only dispatched when at least one field
 * changed.
 */
final class TaskUpdatedEvent extends AbstractTaskEvent
{
    /**
     * @param list<string> $changedFields Names of the changed fields: title, description, priority, column,
     *                                    estimatedMinutes, dueAt, tags, color (since 1.14)
     * @param array<string, mixed> $previous Since 2.3.0 (spec 216, FR-013): the value of each changed field before the write
     *                                       (a `TaskPriority`, a `BoardColumn`, a date, a list of tags…); the new one is on the task
     */
    public function __construct(
        Task $task,
        object $actor,
        private readonly array $changedFields,
        private readonly array $previous = [],
    ) {
        parent::__construct($task, $actor);
    }

    /** Since 2.3.0: the value of `$field` before the write; null when it did not change or was null. */
    public function getPreviousValue(string $field): mixed
    {
        return $this->previous[$field] ?? null;
    }

    /**
     * Since 2.3.0 (spec 216, trigger `tag_added`): the tags the write added.
     *
     * @return list<string>
     */
    public function getAddedTags(): array
    {
        $before = $this->previous['tags'] ?? null;

        return is_array($before) && $this->hasChanged('tags') ? array_values(array_diff($this->getTask()->getTags(), $before)) : [];
    }

    /**
     * Since 2.3.0: the tags the write removed.
     *
     * @return list<string>
     */
    public function getRemovedTags(): array
    {
        $before = $this->previous['tags'] ?? null;

        return is_array($before) && $this->hasChanged('tags') ? array_values(array_diff($before, $this->getTask()->getTags())) : [];
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
