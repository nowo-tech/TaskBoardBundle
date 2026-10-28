<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * Event names for TaskBoardBundle extensibility hooks.
 */
final class TaskBoardEvents
{
    public const BOARD_LIST_QUERY = 'nowo_task_board.board_list_query';

    public const BOARD_LIST_RESULT = 'nowo_task_board.board_list_result';

    public const TASK_LIST_QUERY = 'nowo_task_board.task_list_query';

    public const TASK_LIST_RESULT = 'nowo_task_board.task_list_result';

    public const BOARD_ACCESS_CHECK = 'nowo_task_board.board_access_check';

    public const TASK_ACCESS_CHECK = 'nowo_task_board.task_access_check';

    public const TASK_READ_ONLY_RESOLVE = 'nowo_task_board.task_read_only_resolve';

    public const MEMBER_LIST_QUERY = 'nowo_task_board.member_list_query';

    /** {@see TaskCreatedEvent}, after flush (since 1.10). */
    public const TASK_CREATED = 'nowo_task_board.task_created';

    /** {@see TaskUpdatedEvent}, after flush (since 1.10). */
    public const TASK_UPDATED = 'nowo_task_board.task_updated';

    /** {@see TaskMovedEvent}, after flush (since 1.10). */
    public const TASK_MOVED = 'nowo_task_board.task_moved';

    /** {@see TaskCompletedEvent}, after flush (since 1.10). */
    public const TASK_COMPLETED = 'nowo_task_board.task_completed';

    /** {@see TaskReopenedEvent}, after flush (since 1.10). */
    public const TASK_REOPENED = 'nowo_task_board.task_reopened';

    /** {@see TaskMemberAddedEvent}, after flush (since 1.10). */
    public const TASK_MEMBER_ADDED = 'nowo_task_board.task_member_added';
}
