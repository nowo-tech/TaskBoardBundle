<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\ClickUp;

use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\ImportAssigneeResolver;
use Nowo\TaskBoardBundle\Import\Support\ImportFieldMapper;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;

use function array_keys;
use function array_values;
use function count;
use function filter_var;
use function is_array;
use function is_numeric;
use function is_string;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function strlen;
use function trim;

use const FILTER_VALIDATE_EMAIL;

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
            creator: $this->personRef($row['creator'] ?? null),
            assigneeRefs: $this->personRefs($row['assignees'] ?? null),
        );
    }

    /**
     * Since 1.25: a ClickUp user object ({id, username, email}) as a person ref; null without a valid id. The name is
     * one clean line (controls and bidi overrides removed, at most 120 characters); the e-mail is kept only when
     * valid.
     */
    public function personRef(mixed $user): ?RemotePersonRef
    {
        if (!is_array($user)) {
            return null;
        }
        $id = $this->stringValue($user['id'] ?? null);
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) !== 1) {
            return null;
        }
        $name  = mb_substr(trim((string) preg_replace('/[\p{Cc}\x{061C}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', ' ', $this->stringValue($user['username'] ?? $user['name'] ?? null))), 0, 120);
        $email = $this->stringValue($user['email'] ?? null);

        return new RemotePersonRef($id, $name !== '' ? $name : $id, $email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null);
    }

    /**
     * Since 1.25: a list of user objects as refs (each id once, at most {@see ImportAssigneeResolver::MAX_ASSIGNEES_PER_ROW}).
     *
     * @return list<RemotePersonRef>
     */
    public function personRefs(mixed $users): array
    {
        $refs = [];
        foreach (is_array($users) ? $users : [] as $user) {
            $ref = $this->personRef($user);
            if ($ref instanceof RemotePersonRef && count($refs) < ImportAssigneeResolver::MAX_ASSIGNEES_PER_ROW) {
                $refs[$ref->providerUserId] = $ref;
            }
        }

        return array_values($refs);
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
                if ($email !== null) {
                    $emails[$email] = true;
                }
                // Audit EG-L6: one more than the cap, so the import can say the rest was dropped.
                if (count($emails) > ImportAssigneeResolver::MAX_ASSIGNEES_PER_ROW) {
                    break;
                }
            }
        }

        return array_keys($emails);
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
        $dependencies = $this->extractLinks($row, $selfId);
        if (!is_array($items)) {
            return $dependencies;
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
     * Since 1.27.0 (TB-SYNC-3): ClickUp's `linked_tasks` ({task_id, link_id}: two tasks linked without blocking) as
     * `related` relations to the other task.
     *
     * @param array<mixed> $row
     *
     * @return list<array{0: string, 1: TaskDependencyType}>
     */
    private function extractLinks(array $row, string $selfId): array
    {
        $links = [];
        foreach (is_array($row['linked_tasks'] ?? null) ? $row['linked_tasks'] : [] as $item) {
            $one   = is_array($item) ? $this->stringValue($item['task_id'] ?? null) : '';
            $other = is_array($item) ? $this->stringValue($item['link_id'] ?? null) : '';
            $id    = $one === $selfId ? $other : $one;
            if ($selfId !== '' && $id !== '' && $id !== $selfId && ($one === $selfId || $other === $selfId)) {
                $links[$id] = [$id, TaskDependencyType::Related];
            }
        }

        return array_values($links);
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
