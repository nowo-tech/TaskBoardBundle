<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\TaskBoard;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\Support\ImportFieldMapper;

use function array_map;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function preg_match;
use function preg_split;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Since 1.18: one row of the bundle's own import format (CSV columns or JSON keys, case-insensitive) → a task.
 *
 * Keys: `id`, `parent`, `title` (required), `description`, `status` (column name), `priority` (low, normal, high,
 * urgent), `assignee` (e-mail), `start` and `due` (YYYY-MM-DD), `estimate` (minutes, or `1h30m`), `tags` (`;` or `,`)
 * and `depends_on`: other rows' ids separated by `;` or `,`, each `ID` (this task waits for ID: blocked by) or `ID:type`
 * with type `blocks`, `blocked_by`, `related` or `duplicates`.
 */
final readonly class TaskBoardRowMapper
{
    public const COLUMNS = ['id', 'parent', 'title', 'description', 'status', 'priority', 'assignee', 'start', 'due', 'estimate', 'tags', 'depends_on'];

    public function __construct(
        private ImportFieldMapper $fieldMapper = new ImportFieldMapper(),
    ) {
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException a date or a dependency type that cannot be read (with the row number)
     */
    public function map(array $row, int $line, ?string $parentId = null): ?ImportedTaskDto
    {
        $value = fn (string $key): string => $this->text($row, $key);
        $title = $value('title');
        if ($title === '') {
            return null;
        }
        $parent = $value('parent');

        return new ImportedTaskDto(
            title: $title,
            externalId: $this->nullable($value('id')),
            parentExternalId: $parentId ?? $this->nullable($parent),
            status: $this->nullable($value('status')),
            priority: $this->fieldMapper->mapPriority($value('priority')),
            description: $this->nullable($value('description')),
            assigneeEmail: $this->fieldMapper->normalizeEmail($value('assignee')),
            dueAt: $this->date($value('due'), $line, 'due'),
            estimatedMinutes: $this->fieldMapper->parseEstimatedMinutes($value('estimate')),
            tags: $this->fieldMapper->parseTags($this->list($row, 'tags')),
            startAt: $this->date($value('start'), $line, 'start'),
            dependsOn: $this->dependencies($this->list($row, 'depends_on'), $line),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function text(array $row, string $key): string
    {
        foreach ($row as $name => $value) {
            if (strtolower(trim((string) $name)) === $key && is_scalar($value)) {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * A list cell: a JSON array, or a `;` / `,` separated text.
     *
     * @param array<string, mixed> $row
     */
    private function list(array $row, string $key): string
    {
        foreach ($row as $name => $value) {
            if (strtolower(trim((string) $name)) === $key && is_array($value)) {
                return implode(';', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
            }
        }

        return $this->text($row, $key);
    }

    private function date(string $raw, int $line, string $field): ?DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            throw new InvalidArgumentException(sprintf('Row %d: "%s" is not a date (YYYY-MM-DD) in "%s".', $line, $raw, $field));
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            throw new InvalidArgumentException(sprintf('Row %d: "%s" is not a date (YYYY-MM-DD) in "%s".', $line, $raw, $field));
        }

        return $date;
    }

    /**
     * @return list<array{0: string, 1: TaskDependencyType}>
     */
    private function dependencies(string $raw, int $line): array
    {
        $dependencies = [];
        foreach (preg_split('/[;,]/', $raw) ?: [] as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $parts = explode(':', $item, 2);
            $type  = count($parts) === 2 ? strtolower(trim($parts[1])) : TaskDependencyType::BlockedBy->value;
            $known = array_values(array_map(static fn (TaskDependencyType $case): string => $case->value, TaskDependencyType::cases()));
            if (!in_array($type, $known, true)) {
                throw new InvalidArgumentException(sprintf('Row %d: "%s" is not a dependency type (blocks, blocked_by, related, duplicates).', $line, $type));
            }
            $dependencies[] = [trim($parts[0]), TaskDependencyType::from($type)];
        }

        return $dependencies;
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
