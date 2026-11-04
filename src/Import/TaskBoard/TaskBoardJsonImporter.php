<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\TaskBoard;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\Support\JsonImportGuard;
use Nowo\TaskBoardBundle\Import\TaskImporterInterface;
use Nowo\TaskBoardBundle\Import\TaskImportSource;

use function array_is_list;
use function is_array;
use function json_decode;
use function sprintf;

/**
 * Since 1.18: the bundle's own JSON — a list of tasks (or `{"tasks": [...]}`) with the {@see TaskBoardRowMapper} keys;
 * a task's `subtasks` list nests its children (no `parent` needed), `depends_on` may be a list of ids.
 */
final readonly class TaskBoardJsonImporter implements TaskImporterInterface
{
    public function __construct(
        private TaskBoardRowMapper $rowMapper = new TaskBoardRowMapper(),
        // Since 1.22.1 (audit EB-L3): weighed before json_decode(), items counted before they are mapped.
        private JsonImportGuard $guard = new JsonImportGuard(),
    ) {
    }

    public function supports(TaskImportSource $source): bool
    {
        return $source === TaskImportSource::TaskBoardJson;
    }

    public function parse(string $content, string $filename): array
    {
        $data = $this->guard->decode($content, 'The file is not valid JSON: ');
        $list = is_array($data) && isset($data['tasks']) ? $data['tasks'] : $data;
        if (!is_array($list) || !array_is_list($list)) {
            throw new InvalidArgumentException('The JSON must be a list of tasks or an object with a "tasks" list.');
        }
        $this->guard->assertRows($list);
        $tasks = [];
        $line  = 0;
        $this->walk($list, null, $tasks, $line);
        if ($tasks === []) {
            throw new InvalidArgumentException('No tasks with a "title" were found in the JSON.');
        }

        return $tasks;
    }

    /**
     * @param array<mixed> $list
     * @param list<ImportedTaskDto> $tasks
     */
    private function walk(array $list, ?string $parentId, array &$tasks, int &$line): void
    {
        foreach ($list as $item) {
            // Nested subtasks count against max_rows as they are walked (audit EB-I7).
            $this->guard->assertRowCount(++$line);
            if (!is_array($item)) {
                continue;
            }
            /** @var array<string, mixed> $item */
            $task = $this->rowMapper->map($item, $line, $parentId);
            if (!$task instanceof ImportedTaskDto) {
                continue;
            }
            $tasks[]  = $task;
            $children = $item['subtasks'] ?? null;
            if (is_array($children) && $children !== []) {
                if ($task->externalId === null) {
                    throw new InvalidArgumentException(sprintf('Task %d ("%s") has subtasks but no "id".', $line, $task->title));
                }
                $this->walk($children, $task->externalId, $tasks, $line);
            }
        }
    }
}
