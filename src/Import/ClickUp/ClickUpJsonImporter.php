<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\ClickUp;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\Support\ImportFieldMapper;
use Nowo\TaskBoardBundle\Import\Support\JsonImportGuard;
use Nowo\TaskBoardBundle\Import\TaskImporterInterface;
use Nowo\TaskBoardBundle\Import\TaskImportSource;

use function is_array;
use function is_string;

/**
 * Imports tasks from ClickUp JSON exports.
 */
final readonly class ClickUpJsonImporter implements TaskImporterInterface
{
    public function __construct(
        private ImportFieldMapper $fieldMapper = new ImportFieldMapper(),
        // Since 1.22.1 (audit EB-L3): weighed before json_decode(), items counted before they are mapped.
        private JsonImportGuard $guard = new JsonImportGuard(),
    ) {
    }

    public function supports(TaskImportSource $source): bool
    {
        return $source === TaskImportSource::ClickUpJson;
    }

    public function parse(string $content, string $filename): array
    {
        $decoded = $this->guard->decode($content, 'Invalid ClickUp JSON: ');
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Invalid ClickUp JSON: an object or a list is expected.');
        }

        $rows = $this->extractTaskRows($decoded);
        $this->guard->assertRows($rows);
        if ($rows === []) {
            throw new InvalidArgumentException('No tasks found in the ClickUp JSON export.');
        }

        $tasks = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = $this->stringValue($row['name'] ?? $row['task_name'] ?? $row['title'] ?? null);
            if ($title === '') {
                continue;
            }

            $externalId = $this->stringValue($row['id'] ?? $row['task_id'] ?? null);
            $parentId   = $this->stringValue($row['parent'] ?? $row['parent_id'] ?? null);
            $status     = $this->extractStatus($row);
            $priority   = $this->extractPriority($row);
            $assignee   = $this->extractAssigneeEmail($row);
            $names      = $assignee === null ? $this->extractAssigneeNames($row) : [];

            $tasks[] = new ImportedTaskDto(
                title: $title,
                externalId: $externalId !== '' ? $externalId : null,
                parentExternalId: $parentId !== '' ? $parentId : null,
                status: $status !== '' ? $status : null,
                priority: $this->fieldMapper->mapPriority($priority),
                description: $this->nullable($this->fieldMapper->stripHtml($this->stringValue(
                    $row['text_content'] ?? $row['description'] ?? $row['content'] ?? null,
                ))),
                assigneeEmail: $assignee,
                dueAt: $this->fieldMapper->parseDate($this->stringValue($row['due_date'] ?? $row['dueDate'] ?? null)),
                estimatedMinutes: $this->fieldMapper->parseEstimatedMinutes($this->stringValue(
                    $row['time_estimate'] ?? $row['timeEstimate'] ?? null,
                )),
                tags: $this->extractTags($row),
                sourceUrl: $this->nullable($this->stringValue($row['url'] ?? $row['task_url'] ?? null)),
                // Since 1.22: the Gantt fields, the creation date (fingerprint) and assignees by user name.
                startAt: $this->fieldMapper->parseDate($this->stringValue($row['start_date'] ?? $row['startDate'] ?? null)),
                dependsOn: $this->extractDependencies($row, $externalId),
                createdAt: $this->fieldMapper->parseDate($this->stringValue($row['date_created'] ?? $row['dateCreated'] ?? null)),
                assigneeNames: $names,
                hasPriority: $priority !== '',
            );
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
     * @param array<mixed> $row
     */
    private function extractStatus(array $row): string
    {
        $status = $row['status'] ?? null;
        if (is_array($status)) {
            return $this->stringValue($status['status'] ?? $status['name'] ?? null);
        }

        return $this->stringValue($status);
    }

    /**
     * @param array<mixed> $row
     */
    private function extractPriority(array $row): string
    {
        $priority = $row['priority'] ?? null;
        if (is_array($priority)) {
            return $this->stringValue($priority['priority'] ?? $priority['name'] ?? null);
        }

        return $this->stringValue($priority);
    }

    /**
     * @param array<mixed> $row
     */
    private function extractAssigneeEmail(array $row): ?string
    {
        $assignees = $row['assignees'] ?? $row['assignee'] ?? null;
        if (is_array($assignees)) {
            foreach ($assignees as $assignee) {
                if (!is_array($assignee)) {
                    continue;
                }

                $email = $this->fieldMapper->normalizeEmail($this->stringValue($assignee['email'] ?? null));
                if ($email !== null) {
                    return $email;
                }
            }
        }

        return $this->fieldMapper->normalizeEmail($this->stringValue($assignees));
    }

    /**
     * Since 1.22: the user names of the assignees (when none has an e-mail).
     *
     * @param array<mixed> $row
     *
     * @return list<string>
     */
    private function extractAssigneeNames(array $row): array
    {
        $assignees = $row['assignees'] ?? $row['assignee'] ?? null;
        if (!is_array($assignees)) {
            return $this->fieldMapper->parseNames($this->stringValue($assignees));
        }
        $names = [];
        $seen  = [];
        foreach ($assignees as $assignee) {
            $name = is_array($assignee) ? $this->stringValue($assignee['username'] ?? $assignee['name'] ?? null) : $this->stringValue($assignee);
            if ($name !== '' && !isset($seen[$name])) {
                $seen[$name] = true;
                $names[]     = $name;
            }
        }

        return $names;
    }

    /**
     * Since 1.22: the API's `dependencies` ({task_id, depends_on}: task_id waits on depends_on) seen from this task —
     * blocked by what it waits on, blocks what waits on it; a plain id in the list is a task this one waits on.
     *
     * @param array<mixed> $row
     *
     * @return list<array{0: string, 1: TaskDependencyType}>
     */
    private function extractDependencies(array $row, string $selfId): array
    {
        $items        = $row['dependencies'] ?? null;
        $dependencies = [];
        if (!is_array($items)) {
            return [];
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                $id = $this->stringValue($item);
                if ($id !== '' && $id !== $selfId) {
                    $dependencies[] = [$id, TaskDependencyType::BlockedBy];
                }
                continue;
            }
            $waiting = $this->stringValue($item['task_id'] ?? null);
            $on      = $this->stringValue($item['depends_on'] ?? null);
            if ($selfId === '' || $waiting === '' || $on === '' || $waiting === $on) {
                continue;
            }
            if ($waiting === $selfId) {
                $dependencies[] = [$on, TaskDependencyType::BlockedBy];
            } elseif ($on === $selfId) {
                $dependencies[] = [$waiting, TaskDependencyType::Blocks];
            }
        }

        return $dependencies;
    }

    /**
     * @param array<mixed> $row
     *
     * @return list<string>
     */
    private function extractTags(array $row): array
    {
        $tags = $row['tags'] ?? [];
        if (!is_array($tags)) {
            return $this->fieldMapper->parseTags($this->stringValue($tags));
        }

        $names = [];
        foreach ($tags as $tag) {
            if (is_array($tag)) {
                $name = $this->stringValue($tag['name'] ?? null);
                if ($name !== '') {
                    $names[] = $name;
                }
            } elseif (is_string($tag) && trim($tag) !== '') {
                $names[] = trim($tag);
            }
        }

        return $names;
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

    private function stringValue(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
