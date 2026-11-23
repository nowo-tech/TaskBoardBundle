<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Menu;

use Closure;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;

/**
 * Since 2.22.0 (context menus): what one board page knows once about the signed-in user, for the menus of all its cards,
 * list rows and column headers ({@see TaskMenuBuilder::context()}). Built per page and never kept: FrankenPHP worker mode
 * with the kernel not reset holds nothing of it after the request.
 */
final readonly class TaskMenuContext
{
    /**
     * @param Closure(Task): bool $canEditTask the board's {@see TaskBoardResourceAccessChecker::taskEditDecider()}
     */
    public function __construct(
        public object $user,
        public TaskBoard $board,
        public bool $canEditBoard,
        public bool $canManageBoard,
        public bool $canManageColumns,
        public Closure $canEditTask,
        public bool $copyEnabled,
        public bool $timerEnabled,
        // Since 2.23.0 (Beacon spec 242): the board's TaskBoardResourceAccessChecker::askQuestionDecider(); null: questions off.
        public ?Closure $canAskQuestion = null,
    ) {
    }

    /** Since 2.23.0: whether the menu offers *Ask a question…* for the task (false with questions off). */
    public function canAskQuestion(Task $task): bool
    {
        return $this->canAskQuestion instanceof Closure && ($this->canAskQuestion)($task) === true;
    }

    public function canEditTask(Task $task): bool
    {
        return ($this->canEditTask)($task);
    }
}
