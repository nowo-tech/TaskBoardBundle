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
}
