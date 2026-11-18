<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Menu;

use Nowo\TaskBoardBundle\Controller\TaskMenuController;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskPriority;

/**
 * Since 2.22.0 (context menus): the entries of one task's menu (card or list row) the signed-in user may use, decided by
 * {@see TaskMenuBuilder::forTask()}. The template prints only these; {@see TaskMenuController}
 * checks every write again on the server. A value: nothing outlives the page.
 */
final readonly class TaskMenu
{
    /**
     * @param list<BoardColumn> $statuses the board's other columns (Status submenu)
     * @param list<TaskPriority> $priorities the other priorities (Priority submenu)
     * @param list<string> $dueChoices {@see TaskMenuOperation::DUE_CHOICES} (Due date submenu)
     */
    public function __construct(
        public Task $task,
        public bool $edit,
        public bool $assignMe,
        public bool $unassignMe,
        public bool $assignOthers,
        public array $statuses,
        public array $priorities,
        public array $dueChoices,
        public bool $tags,
        public bool $timer,
        public bool $copy,
        public bool $archive,
        public bool $delete,
    ) {
    }

    /** Whether the menu writes anything (its form and CSRF token are printed only then). */
    public function writes(): bool
    {
        return $this->assignMe || $this->unassignMe || $this->statuses !== [] || $this->priorities !== [] || $this->dueChoices !== [] || $this->timer || $this->archive || $this->delete;
    }
}
