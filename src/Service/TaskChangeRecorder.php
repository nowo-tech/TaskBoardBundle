<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;

use function is_array;
use function is_bool;

use const JSON_THROW_ON_ERROR;

/**
 * Records auditable changes on tasks.
 */
final readonly class TaskChangeRecorder
{
    public function record(
        Task $task,
        object $user,
        TaskChangeType $changeType,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?string $context = null,
    ): void {
        if ($oldValue === $newValue && $changeType !== TaskChangeType::Created) {
            return;
        }

        $task->addChangeHistory(new TaskChangeHistory(
            task: $task,
            user: $user,
            changeType: $changeType,
            oldValue: $oldValue,
            newValue: $newValue,
            context: $context,
        ));
    }

    public function recordIfChanged(
        Task $task,
        object $user,
        TaskChangeType $changeType,
        mixed $old,
        mixed $new,
        ?string $context = null,
    ): void {
        $this->record(
            $task,
            $user,
            $changeType,
            $this->formatValue($old),
            $this->formatValue($new),
            $context,
        );
    }

    public function recordCreated(Task $task, object $user): void
    {
        $this->record($task, $user, TaskChangeType::Created, null, $task->getTitle());
    }

    private function formatValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d');
        }

        if ($value instanceof TaskPriority) {
            return $value->value;
        }

        if ($value instanceof BoardColumn) {
            return $value->getName();
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            $tags = $value;
            sort($tags);

            return json_encode($tags, JSON_THROW_ON_ERROR);
        }

        return (string) $value;
    }
}
