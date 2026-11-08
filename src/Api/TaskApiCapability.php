<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api;

use function in_array;

/**
 * Since 2.2.0 (spec 213): what an operation asks of the token. Anything a level does not list here is denied.
 */
enum TaskApiCapability
{
    /** Boards, spaces, identity, and the tasks a level may read: for My tasks, its own. */
    case ReadOwn;

    /** Every task, comment, member and time entry the person sees. */
    case ReadEverything;

    /** On a task within reach: status, dates, description, checklists, own time. */
    case WorkOnTask;

    /** Comment on a task within reach. */
    case Comment;

    /** Create, edit, assign and move any task, tags, links, files. */
    case EditTasks;

    /** Delete a task. */
    case DeleteTask;

    /** Boards and columns: create, edit, delete. */
    case ManageBoards;

    public function allows(TaskApiLevel $level): bool
    {
        return match ($level) {
            TaskApiLevel::MyTasks => in_array($this, [self::ReadOwn, self::WorkOnTask, self::Comment], true),
            TaskApiLevel::Read    => in_array($this, [self::ReadOwn, self::ReadEverything], true),
            TaskApiLevel::Tasks   => $this !== self::ManageBoards,
            TaskApiLevel::Full    => true,
        };
    }
}
