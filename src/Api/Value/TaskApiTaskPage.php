<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Entity\TaskCustomFieldValue;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;

/**
 * Since 2.2.0 (spec 213): tasks a token may read, with everything needed to show them, loaded in a fixed number of queries.
 */
final readonly class TaskApiTaskPage
{
    /**
     * @param list<Task> $tasks
     * @param array<string, TaskApiSpace> $spaces by board id
     * @param array<string, TaskApiUser> $profiles by API id, as the viewer may see them
     * @param array<int, string> $userIds API id by `spl_object_id()` of each user shown
     * @param list<TaskDependency> $dependencies
     * @param array<string, list<Task>> $subtasks by parent id
     * @param array<string, int> $manualMinutes by task id
     * @param array<string, list<TaskSyncFieldValue>> $fieldValues by task id
     * @param array<string, list<TaskCustomField>> $customFields since 2.18.0: the native fields of each board, by board id
     * @param array<string, list<TaskCustomFieldValue>> $customFieldValues since 2.18.0: native values, by task id
     */
    public function __construct(
        public array $tasks,
        public bool $lastPage,
        public array $spaces,
        public array $profiles,
        public array $userIds,
        public array $dependencies,
        public array $subtasks,
        public array $manualMinutes,
        public array $fieldValues,
        public bool $markdown,
        public array $customFields = [],
        public array $customFieldValues = [],
    ) {
    }

    /** The profile of a user shown on the page, or null when the host leaves them out. */
    public function profileOf(?object $user): ?TaskApiUser
    {
        if ($user === null) {
            return null;
        }
        $id = $this->userIds[spl_object_id($user)] ?? null;

        return $id === null ? null : ($this->profiles[$id] ?? null);
    }
}
