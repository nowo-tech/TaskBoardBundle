<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\ClickUp;

use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\Support\ImportFieldMapper;

use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function trim;

/**
 * Since 1.23: one ClickUp task (API v2 / JSON export shape) as an import row — shared by {@see ClickUpJsonImporter}
 * and the ClickUp provider sync, so a file import and a sync map the same fields the same way.
 */
final readonly class ClickUpTaskMapper
{
    public function __construct(
        private ImportFieldMapper $fieldMapper = new ImportFieldMapper(),
    ) {
    }

    /**
     * The row of a task; null without a name. `$description` replaces the plain-text description of the export (the
     * sync passes the sanitized Markdown description).
     *
     * @param array<mixed> $row
     */
    public function map(array $row, ?string $description = null): ?ImportedTaskDto
    {
        $title = $this->stringValue($row['name'] ?? $row['task_name'] ?? $row['title'] ?? null);
        if ($title === '') {
            return null;
        }

        $externalId = $this->stringValue($row['id'] ?? $row['task_id'] ?? null);
        $parentId   = $this->stringValue($row['parent'] ?? $row['parent_id'] ?? null);
        $status     = $this->extractStatus($row);
        $priority   = $this->extractPriority($row);
        $emails     = $this->extractAssigneeEmails($row);
        $assignee   = $emails[0] ?? $this->fieldMapper->normalizeEmail($this->stringValue($row['assignees'] ?? $row['assignee'] ?? null));
        $names      = $assignee === null ? $this->extractAssigneeNames($row) : [];

        return new ImportedTaskDto(
            title: $title,
            externalId: $externalId !== '' ? $externalId : null,
            parentExternalId: $parentId !== '' ? $parentId : null,
            status: $status !== '' ? $status : null,
            priority: $this->fieldMapper->mapPriority($priority),
            description: $description ?? $this->nullable($this->fieldMapper->stripHtml($this->stringValue(
                $row['text_content'] ?? $row['description'] ?? $row['content'] ?? null,
            ))),
            assigneeEmail: $assignee,
            dueAt: $this->fieldMapper->parseDate($this->stringValue($row['due_date'] ?? $row['dueDate'] ?? null)),
            estimatedMinutes: $this->fieldMapper->parseEstimatedMinutes($this->stringValue(
                $row['time_estimate'] ?? $row['timeEstimate'] ?? null,
            )),
            tags: $this->extractTags($row),
            sourceUrl: $this->nullable($this->stringValue($row['url'] ?? $row['task_url'] ?? null)),
            startAt: $this->fieldMapper->parseDate($this->stringValue($row['start_date'] ?? $row['startDate'] ?? null)),
            dependsOn: $this->extractDependencies($row, $externalId),
            createdAt: $this->fieldMapper->parseDate($this->stringValue($row['date_created'] ?? $row['dateCreated'] ?? null)),
            assigneeNames: $names,
            hasPriority: $priority !== '',
            assigneeEmails: $emails,
        );
    }

    /**
     * Since 1.23: every assignee e-mail, each once.
     *
     * @param array<mixed> $row
     *
     * @return list<string>
     */
    private function extractAssigneeEmails(array $row): array
    {
        $assignees = $row['assignees'] ?? $row['assignee'] ?? null;
        $emails    = [];
        if (is_array($assignees)) {
            foreach ($assignees as $assignee) {
                $email = is_array($assignee) ? $this->fieldMapper->normalizeEmail($this->stringValue($assignee['email'] ?? null)) : null;
                if ($email !== null && !in_array($email, $emails, true)) {
                    $emails[] = $email;
                }
            }
        }

        return $emails;
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
