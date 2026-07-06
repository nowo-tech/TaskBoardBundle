<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Jira;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\Support\DelimitedTableParser;
use Nowo\TaskBoardBundle\Import\Support\ImportFieldMapper;
use Nowo\TaskBoardBundle\Import\TaskImporterInterface;
use Nowo\TaskBoardBundle\Import\TaskImportSource;

/**
 * Imports issues from Jira CSV exports.
 */
final readonly class JiraCsvImporter implements TaskImporterInterface
{
    public function __construct(
        private DelimitedTableParser $tableParser = new DelimitedTableParser(),
        private ImportFieldMapper $fieldMapper = new ImportFieldMapper(),
    ) {
    }

    public function supports(TaskImportSource $source): bool
    {
        return $source === TaskImportSource::JiraCsv;
    }

    public function parse(string $content, string $filename): array
    {
        $rows = $this->tableParser->parse($content, $filename);
        if ($rows === []) {
            throw new InvalidArgumentException('The Jira CSV file is empty or has no data rows.');
        }

        $tasks = [];
        foreach ($rows as $row) {
            $title = $this->fieldMapper->pick($row, 'Summary', 'summary', 'Issue summary');
            if ($title === '') {
                continue;
            }

            $externalId = $this->fieldMapper->pick($row, 'Issue key', 'Key', 'Issue Key', 'issue key');
            $parentKey  = $this->fieldMapper->pick($row, 'Parent key', 'Parent', 'parent key');
            $assignee   = $this->fieldMapper->normalizeEmail($this->fieldMapper->pick(
                $row,
                'Assignee',
                'Assignee Id',
                'assignee',
            ));

            $tasks[] = new ImportedTaskDto(
                title: $title,
                externalId: $externalId !== '' ? $externalId : null,
                parentExternalId: $parentKey !== '' ? $parentKey : null,
                status: $this->nullable($this->fieldMapper->pick($row, 'Status', 'status')),
                priority: $this->fieldMapper->mapPriority($this->fieldMapper->pick($row, 'Priority', 'priority')),
                description: $this->nullable($this->fieldMapper->stripHtml($this->fieldMapper->pick(
                    $row,
                    'Description',
                    'description',
                ))),
                assigneeEmail: $assignee,
                dueAt: $this->fieldMapper->parseDueDate($this->fieldMapper->pick($row, 'Due Date', 'duedate', 'Due date')),
                estimatedMinutes: $this->fieldMapper->parseEstimatedMinutes($this->fieldMapper->pick(
                    $row,
                    'Original Estimate',
                    'Time Spent',
                    'Remaining Estimate',
                )),
                tags: $this->fieldMapper->parseTags($this->fieldMapper->pick($row, 'Labels', 'labels')),
                sourceUrl: $this->nullable($this->fieldMapper->pick($row, 'URL', 'Issue URL', 'url')),
            );
        }

        if ($tasks === []) {
            throw new InvalidArgumentException('No issues with a Summary column were found in the Jira CSV.');
        }

        return $tasks;
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
