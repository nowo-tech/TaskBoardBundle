<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskLink;

use function sprintf;

/**
 * Supported task import formats: third-party exports and, since 1.18, the bundle's own CSV / JSON (hierarchy by
 * `parent` or nested `subtasks`, start dates and Gantt dependencies).
 */
enum TaskImportSource: string
{
    case ClickUpCsv    = 'clickup_csv';
    case ClickUpJson   = 'clickup_json';
    case JiraCsv       = 'jira_csv';
    case TrelloJson    = 'trello_json';
    case TaskBoardCsv  = 'taskboard_csv';
    case TaskBoardJson = 'taskboard_json';

    public function labelKey(): string
    {
        return match ($this) {
            self::ClickUpCsv    => 'task_board.import.source.clickup_csv',
            self::ClickUpJson   => 'task_board.import.source.clickup_json',
            self::JiraCsv       => 'task_board.import.source.jira_csv',
            self::TrelloJson    => 'task_board.import.source.trello_json',
            self::TaskBoardCsv  => 'task_board.import.source.taskboard_csv',
            self::TaskBoardJson => 'task_board.import.source.taskboard_json',
        };
    }

    /**
     * @return list<string>
     */
    public function acceptedExtensions(): array
    {
        return match ($this) {
            self::ClickUpCsv, self::JiraCsv, self::TaskBoardCsv      => ['csv', 'txt', 'tsv'],
            self::ClickUpJson, self::TrelloJson, self::TaskBoardJson => ['json'],
        };
    }

    /**
     * Since 1.22: the tool the ids come from. The CSV and JSON exports of one tool share their ids, so a ClickUp task
     * imported from CSV is matched again by a JSON import (and the other way round).
     */
    public function provider(): string
    {
        return match ($this) {
            self::ClickUpCsv, self::ClickUpJson     => 'clickup',
            self::JiraCsv                           => 'jira',
            self::TrelloJson                        => 'trello',
            self::TaskBoardCsv, self::TaskBoardJson => 'taskboard',
        };
    }

    /**
     * Since 1.22: whether the ids name one task in the source tool (ClickUp, Jira, Trello) rather than one row of a file
     * (the bundle's own format, whose ids only link the rows of the file, e.g. a subtask template).
     */
    public function hasGlobalIds(): bool
    {
        return $this->provider() !== 'taskboard';
    }

    /**
     * Since 1.22: the import scope stored on the task links ({@see TaskLink::getImportSource()}):
     * the provider on a board import, and "<provider>@<task id>" when importing into a task (every source), so a
     * subtask import only ever matches the tasks an earlier import put under that same task (one template can be
     * imported under several tasks, and importing into one task can never update other tasks of the board).
     */
    public function scope(?Task $parentTask = null): string
    {
        if (!$parentTask instanceof Task) {
            return $this->provider();
        }

        return sprintf('%s@%s', $this->provider(), $parentTask->getId());
    }
}
