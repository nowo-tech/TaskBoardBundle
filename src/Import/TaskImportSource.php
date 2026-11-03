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
     * the provider for tools with global ids; for the bundle's own format, "taskboard" on a board import and
     * "taskboard@<task id>" when importing into a task, so one template can be imported under several tasks.
     */
    public function scope(?Task $parentTask = null): string
    {
        if ($this->hasGlobalIds() || !$parentTask instanceof Task) {
            return $this->provider();
        }

        return sprintf('%s@%s', $this->provider(), $parentTask->getId());
    }

    /**
     * Since 1.22: the source of a link written by an import before 1.22 (no `import_source`), from the label those
     * versions wrote ("clickup_csv #<id>"); null when the label does not have that shape.
     */
    public static function fromLegacyLabel(?string $label, ?string $externalId): ?self
    {
        if ($label === null || $externalId === null || $externalId === '') {
            return null;
        }
        foreach (self::cases() as $case) {
            if ($label === sprintf('%s #%s', $case->value, $externalId)) {
                return $case;
            }
        }

        return null;
    }
}
