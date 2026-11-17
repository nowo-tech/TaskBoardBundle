<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Menu;

use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Service\TaskBoardEraserInterface;
use Nowo\TaskBoardBundle\Service\TaskManager;

use function explode;

/**
 * Since 2.22.0 (context menus): the writes of the task menu, posted as `action` ({@see self::value()}) to the `task_menu`
 * route with the CSRF token {@see self::CSRF_TOKEN_ID}. Each one goes through the service the rest of the UI uses.
 */
final class TaskMenuOperation
{
    public const CSRF_TOKEN_ID = 'task_board_task_menu';

    /** `value`: a column id of the task's board ({@see TaskManager::moveToColumn()}). */
    public const STATUS = 'status';

    /** `value`: a {@see TaskPriority} value. */
    public const PRIORITY = 'priority';

    /** `value`: one of {@see self::DUE_CHOICES}. */
    public const DUE = 'due';

    public const ASSIGN_ME = 'assign_me';

    public const UNASSIGN_ME = 'unassign_me';

    /** TimeTrack integration: starts the user's timer on the task. */
    public const TIMER = 'timer';

    public const ARCHIVE = 'archive';

    /** Board managers only ({@see TaskBoardEraserInterface::eraseTask()}). */
    public const DELETE = 'delete';

    public const ALL = [self::STATUS, self::PRIORITY, self::DUE, self::ASSIGN_ME, self::UNASSIGN_ME, self::TIMER, self::ARCHIVE, self::DELETE];

    /** Due date: today, tomorrow, the same weekday next week, or no due date. */
    public const DUE_CHOICES = ['today', 'tomorrow', 'next_week', 'none'];

    /** The `action` value of an entry: `<task id>|<operation>[:<value>]`. */
    public static function value(string $taskId, string $operation, ?string $value = null): string
    {
        return $taskId . '|' . $operation . ($value !== null ? ':' . $value : '');
    }

    /**
     * @return array{0: string, 1: string, 2: string} the task id, the operation and the value ('' when missing)
     */
    public static function parse(string $action): array
    {
        [$taskId, $rest]     = explode('|', $action, 2) + [1 => ''];
        [$operation, $value] = explode(':', $rest, 2) + [1 => ''];

        return [$taskId, $operation, $value];
    }
}
