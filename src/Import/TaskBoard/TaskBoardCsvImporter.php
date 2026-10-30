<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\TaskBoard;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Import\Support\DelimitedTableParser;
use Nowo\TaskBoardBundle\Import\TaskImporterInterface;
use Nowo\TaskBoardBundle\Import\TaskImportSource;

/**
 * Since 1.18: the bundle's own CSV — one task per row with {@see TaskBoardRowMapper::COLUMNS}; `parent` builds the
 * subtask tree and `depends_on` the Gantt relations between the rows.
 */
final readonly class TaskBoardCsvImporter implements TaskImporterInterface
{
    public function __construct(
        private DelimitedTableParser $tableParser = new DelimitedTableParser(),
        private TaskBoardRowMapper $rowMapper = new TaskBoardRowMapper(),
    ) {
    }

    public function supports(TaskImportSource $source): bool
    {
        return $source === TaskImportSource::TaskBoardCsv;
    }

    public function parse(string $content, string $filename): array
    {
        $tasks = [];
        foreach ($this->tableParser->parse($content, $filename) as $index => $row) {
            $task = $this->rowMapper->map($row, $index + 2);
            if ($task !== null) {
                $tasks[] = $task;
            }
        }
        if ($tasks === []) {
            throw new InvalidArgumentException('No rows with a "title" column were found in the CSV.');
        }

        return $tasks;
    }
}
