<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;

use function is_array;
use function is_bool;

use const JSON_THROW_ON_ERROR;

/**
 * Records auditable changes on tasks.
 */
final readonly class TaskChangeRecorder
{
    /**
     * @param TaskChangeContext|null $cause since 2.3.0 (spec 216): the cause written on every row recorded while it is set
     */
    public function __construct(private ?TaskChangeContext $cause = null)
    {
    }

    /**
     * Since 1.29.0 (audit HId-L4): a member added or removed. The role and the member's id share `context`
     * ("assignee user:7"), so an erasure finds the member's own rows and not those of someone with the same label.
     */
    public function recordMember(Task $task, object $actor, TaskChangeType $changeType, object $member, string $role, ?string $label = null): void
    {
        $label ??= UserLabel::of($member);
        $id = SyncUser::id($member);
        $this->record($task, $actor, $changeType, $changeType === TaskChangeType::MemberRemoved ? $label : null, $changeType === TaskChangeType::MemberAdded ? $label : null, $id === null ? $role : $role . ' user:' . $id);
    }

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
            cause: $this->cause?->current(),
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

    /** Since 2.3.0: `$context` says why (spec 215: "recurring"). */
    public function recordCreated(Task $task, object $user, ?string $context = null): void
    {
        $this->record($task, $user, TaskChangeType::Created, null, $task->getTitle(), $context);
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
