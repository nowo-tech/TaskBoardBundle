<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\TaskBoard;

use InvalidArgumentException;
use JsonException;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\TaskImporterInterface;
use Nowo\TaskBoardBundle\Import\TaskImportSource;

use function array_is_list;
use function is_array;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.18: the bundle's own JSON — a list of tasks (or `{"tasks": [...]}`) with the {@see TaskBoardRowMapper} keys;
 * a task's `subtasks` list nests its children (no `parent` needed), `depends_on` may be a list of ids.
 */
final readonly class TaskBoardJsonImporter implements TaskImporterInterface
{
    public function __construct(
        private TaskBoardRowMapper $rowMapper = new TaskBoardRowMapper(),
    ) {
    }

    public function supports(TaskImportSource $source): bool
    {
        return $source === TaskImportSource::TaskBoardJson;
    }

    public function parse(string $content, string $filename): array
    {
        try {
            $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The file is not valid JSON.');
        }
        $list = is_array($data) && isset($data['tasks']) ? $data['tasks'] : $data;
        if (!is_array($list) || !array_is_list($list)) {
            throw new InvalidArgumentException('The JSON must be a list of tasks or an object with a "tasks" list.');
        }
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
            ++$line;
            if (!is_array($item)) {
                continue;
            }
            /** @var array<string, mixed> $item */
            $task = $this->rowMapper->map($item, $line, $parentId);
            if ($task === null) {
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
