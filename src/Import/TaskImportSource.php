<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

/**
 * Supported third-party task import formats.
 */
enum TaskImportSource: string
{
    case ClickUpCsv  = 'clickup_csv';
    case ClickUpJson = 'clickup_json';
    case JiraCsv     = 'jira_csv';
    case TrelloJson  = 'trello_json';

    public function labelKey(): string
    {
        return match ($this) {
            self::ClickUpCsv  => 'task_board.import.source.clickup_csv',
            self::ClickUpJson => 'task_board.import.source.clickup_json',
            self::JiraCsv     => 'task_board.import.source.jira_csv',
            self::TrelloJson  => 'task_board.import.source.trello_json',
        };
    }

    /**
     * @return list<string>
     */
    public function acceptedExtensions(): array
    {
        return match ($this) {
            self::ClickUpCsv, self::JiraCsv     => ['csv', 'txt', 'tsv'],
            self::ClickUpJson, self::TrelloJson => ['json'],
        };
    }
}
