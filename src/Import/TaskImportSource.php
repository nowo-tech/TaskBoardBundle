<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

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
}
