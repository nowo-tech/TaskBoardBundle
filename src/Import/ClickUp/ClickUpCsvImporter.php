<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\ClickUp;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\Support\DelimitedTableParser;
use Nowo\TaskBoardBundle\Import\Support\ImportFieldMapper;
use Nowo\TaskBoardBundle\Import\TaskImporterInterface;
use Nowo\TaskBoardBundle\Import\TaskImportSource;

use function trim;

/**
 * Imports tasks from ClickUp CSV exports (Workspace export or List/Table view export).
 *
 * Since 1.22 also: Start Date (the Gantt bar), Date Created (fingerprint of rows without a Task ID), Dependencies /
 * Waiting On (blocked by) and Blocking (blocks) by task id, assignees by name, and ClickUp's dates (millisecond
 * timestamps and the "Friday, March 1st 2024, 9:46:52 am" text). Time Spent has no target: logged time is made of
 * time entries of a person in the host, which an export cannot name.
 */
final readonly class ClickUpCsvImporter implements TaskImporterInterface
{
    public function __construct(
        private DelimitedTableParser $tableParser = new DelimitedTableParser(),
        private ImportFieldMapper $fieldMapper = new ImportFieldMapper(),
    ) {
    }

    public function supports(TaskImportSource $source): bool
    {
        return $source === TaskImportSource::ClickUpCsv;
    }

    public function parse(string $content, string $filename): array
    {
        $rows = $this->tableParser->parse($content, $filename);
        if ($rows === []) {
            throw new InvalidArgumentException('The ClickUp CSV file is empty or has no data rows.');
        }

        $tasks = [];
        foreach ($rows as $row) {
            $title = $this->fieldMapper->pick($row, 'Task Name', 'Task name', 'Name', 'task name');
            if ($title === '') {
                continue;
            }

            $externalId = $this->fieldMapper->pick($row, 'Task ID', 'Task id', 'ID', 'id');
            $parentId   = $this->fieldMapper->pick($row, 'Parent ID', 'Parent id', 'Parent Task ID', 'Parent task id');
            if ($parentId === '') {
                $parentId = $this->fieldMapper->pick($row, 'Parent', 'parent');
            }

            $assigneeRaw = $this->fieldMapper->pick(
                $row,
                'Assignee Email',
                'Assignee email',
                'Assignee',
                'Assignees',
                'assignee',
            );
            $priority = $this->fieldMapper->pick($row, 'Priority', 'priority');

            $tasks[] = new ImportedTaskDto(
                title: $title,
                externalId: $externalId !== '' ? $externalId : null,
                parentExternalId: $parentId !== '' ? $parentId : null,
                status: $this->nullable($this->fieldMapper->pick($row, 'Status', 'status', 'Stage')),
                priority: $this->fieldMapper->mapPriority($priority),
                description: $this->nullable($this->fieldMapper->stripHtml($this->fieldMapper->pick(
                    $row,
                    'Task Content',
                    'Task content',
                    'Content',
                    'Description',
                    'description',
                ))),
                assigneeEmail: $this->fieldMapper->normalizeEmail($assigneeRaw),
                dueAt: $this->fieldMapper->parseDate($this->fieldMapper->pick(
                    $row,
                    'Due Date',
                    'Due date',
                    'due date',
                    'Due Date (date)',
                    'Due Date Text',
                )),
                estimatedMinutes: $this->fieldMapper->parseEstimatedMinutes($this->fieldMapper->pick(
                    $row,
                    'Time Estimate',
                    'Time Estimated',
                    'Estimate',
                    'Time estimate',
                    'Time Estimated Text',
                )),
                tags: $this->fieldMapper->parseTags($this->fieldMapper->pick($row, 'Tags', 'tags')),
                sourceUrl: $this->nullable($this->fieldMapper->pick(
                    $row,
                    'Task URL',
                    'URL',
                    'Link',
                    'task url',
                )),
                // Since 1.22: the Gantt fields of a List / Table view export.
                startAt: $this->fieldMapper->parseDate($this->fieldMapper->pick(
                    $row,
                    'Start Date',
                    'Start date',
                    'start date',
                    'Start Date Text',
                )),
                dependsOn: $this->fieldMapper->mapDependencies(
                    $this->fieldMapper->pick($row, 'Dependencies', 'dependencies') . ';' . $this->fieldMapper->pick($row, 'Waiting On', 'Waiting on', 'waiting on'),
                    $this->fieldMapper->pick($row, 'Blocking', 'blocking'),
                    $externalId !== '' ? $externalId : null,
                ),
                createdAt: $this->fieldMapper->parseDate($this->fieldMapper->pick(
                    $row,
                    'Date Created',
                    'Date created',
                    'Created',
                    'Date Created Text',
                )),
                assigneeNames: $this->fieldMapper->parseNames($assigneeRaw),
                hasPriority: $priority !== '',
            );
        }

        if ($tasks === []) {
            throw new InvalidArgumentException('No tasks with a Task Name column were found in the ClickUp CSV.');
        }

        return $tasks;
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
