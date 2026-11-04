<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\ClickUp;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\TaskImporterInterface;
use Nowo\TaskBoardBundle\Import\TaskImportSource;

use function is_array;
use function json_decode;
use function json_last_error_msg;

/**
 * Imports tasks from ClickUp JSON exports.
 */
final readonly class ClickUpJsonImporter implements TaskImporterInterface
{
    public function __construct(
        // Since 1.23: the mapping is shared with the ClickUp provider sync.
        private ClickUpTaskMapper $mapper = new ClickUpTaskMapper(),
    ) {
    }

    public function supports(TaskImportSource $source): bool
    {
        return $source === TaskImportSource::ClickUpJson;
    }

    public function parse(string $content, string $filename): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Invalid ClickUp JSON: ' . json_last_error_msg());
        }

        $rows = $this->extractTaskRows($decoded);
        if ($rows === []) {
            throw new InvalidArgumentException('No tasks found in the ClickUp JSON export.');
        }

        $tasks = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $task = $this->mapper->map($row);
            if ($task instanceof ImportedTaskDto) {
                $tasks[] = $task;
            }
        }

        if ($tasks === []) {
            throw new InvalidArgumentException('No valid tasks found in the ClickUp JSON export.');
        }

        return $tasks;
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return list<mixed>
     */
    private function extractTaskRows(array $decoded): array
    {
        if (isset($decoded['tasks']) && is_array($decoded['tasks'])) {
            return array_values($decoded['tasks']);
        }

        if ($this->isList($decoded)) {
            return array_values($decoded);
        }

        foreach ($decoded as $value) {
            if (is_array($value) && $this->isList($value)) {
                return array_values($value);
            }
        }

        return [];
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_is_list($value) && is_array($value[0] ?? null);
    }
}
