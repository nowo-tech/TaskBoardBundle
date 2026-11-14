<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Serializer;

use DateTimeInterface;
use Nowo\TaskBoardBundle\Api\Value\TaskApiSpace;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskPage;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUser;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;

use function array_key_last;
use function array_map;
use function array_slice;
use function array_values;
use function base64_encode;
use function count;
use function in_array;
use function mb_strtoupper;
use function mb_substr;
use function preg_split;
use function rtrim;
use function str_starts_with;
use function strtr;
use function substr;
use function trim;
use function usort;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Since 2.2.0 (spec 213): what the read API answers, in ClickUp API v2's field names. Dates are Unix milliseconds as strings,
 * ids are the bundle's and the host's strings. Fields Beacon has no equivalent for are null or empty (docs/API.md).
 */
final class TaskApiReadSerializer
{
    /** ClickUp's priorities: id, name, colour. */
    private const PRIORITIES = [
        'urgent' => ['1', '#f50000'],
        'high'   => ['2', '#f8ae00'],
        'normal' => ['3', '#6fddff'],
        'low'    => ['4', '#d8d8d8'],
    ];

    private const CHECKLIST_TAG = 'checklist:';

    /** A board is a folderless list: its folder is hidden, as ClickUp shows those. */
    private const HIDDEN_FOLDER = ['id' => null, 'name' => 'hidden', 'hidden' => true, 'access' => true];

    /**
     * @return array<string, mixed>
     */
    public function space(TaskApiSpace $space): array
    {
        return [
            'id'                 => $space->id,
            'name'               => $space->name,
            'private'            => false,
            'color'              => null,
            'avatar'             => null,
            'admin_can_manage'   => false,
            'archived'           => false,
            'members'            => [],
            'statuses'           => [],
            'multiple_assignees' => true,
            'features'           => [
                'due_dates'          => ['enabled' => true, 'start_date' => true, 'remap_due_dates' => false, 'remap_closed_due_date' => false],
                'time_tracking'      => ['enabled' => true],
                'tags'               => ['enabled' => true],
                'time_estimates'     => ['enabled' => true],
                'checklists'         => ['enabled' => true],
                'custom_fields'      => ['enabled' => true],
                'remap_dependencies' => ['enabled' => false],
                'dependency_warning' => ['enabled' => true],
                'portfolios'         => ['enabled' => false],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function board(TaskBoard $board, TaskApiSpace $space, int $taskCount): array
    {
        return [
            'id'                => $board->getId(),
            'name'              => $board->getName(),
            'orderindex'        => 0,
            'content'           => (string) $board->getDescription(),
            'status'            => null,
            'priority'          => null,
            'assignee'          => null,
            'task_count'        => $taskCount,
            'due_date'          => null,
            'start_date'        => null,
            'folder'            => $this->folderRef($board),
            'space'             => ['id' => $space->id, 'name' => $space->name, 'access' => true],
            'archived'          => $board->isArchived(),
            'override_statuses' => true,
            'statuses'          => $this->statuses($board),
        ];
    }

    /**
     * Since 2.19.0 (Beacon spec 234, FR-031): a folder in ClickUp's shape, with its lists (the boards in it the token may
     * view). `content` / `markdown_content`: its Markdown description.
     *
     * @param list<TaskBoard> $boards
     * @param array<string, int> $taskCounts by board id
     *
     * @return array<string, mixed>
     */
    public function folder(TaskBoardFolder $folder, TaskApiSpace $space, array $boards, array $taskCounts): array
    {
        $count = 0;
        foreach ($boards as $board) {
            $count += $taskCounts[$board->getId()] ?? 0;
        }

        return [
            'id'                => $folder->getId(),
            'name'              => $folder->getName(),
            'orderindex'        => $folder->getPosition(),
            'content'           => (string) $folder->getDescription(),
            'markdown_content'  => (string) $folder->getDescription(),
            'override_statuses' => false,
            'hidden'            => false,
            'space'             => ['id' => $space->id, 'name' => $space->name, 'access' => true],
            'task_count'        => (string) $count,
            'archived'          => false,
            'statuses'          => [],
            'lists'             => array_map(fn (TaskBoard $board): array => $this->board($board, $space, $taskCounts[$board->getId()] ?? 0), $boards),
            'permission_level'  => 'read',
        ];
    }

    /**
     * Since 2.19.0: the folder a board is in, or the hidden folder of a folderless list (as ClickUp shows those).
     *
     * @return array<string, mixed>
     */
    private function folderRef(TaskBoard $board): array
    {
        $folder = $board->getFolder();

        return $folder instanceof TaskBoardFolder ? ['id' => $folder->getId(), 'name' => $folder->getName(), 'hidden' => false, 'access' => true] : self::HIDDEN_FOLDER;
    }

    /**
     * The board's columns as statuses: the first is `open`, the last `closed` (its tasks are done), the rest `custom`.
     *
     * @return list<array<string, mixed>>
     */
    public function statuses(TaskBoard $board): array
    {
        $columns = $this->ordered($board);

        return array_map(fn (BoardColumn $column): array => $this->status($column, $columns), $columns);
    }

    /**
     * @return array<string, mixed>
     */
    public function task(Task $task, TaskApiTaskPage $page): array
    {
        $board       = $task->getBoard();
        $space       = $page->spaces[$board->getId()];
        $columns     = $this->ordered($board);
        $column      = $task->getColumn();
        $spent       = $task->getTotalTimeSeconds() * 1000 + ($page->manualMinutes[$task->getId()] ?? 0) * 60000;
        $description = (string) $task->getDescription();
        $updatedBy   = $page->profileOf($task->getUpdatedBy());

        $data = [
            'id'        => $task->getId(),
            'custom_id' => null,
            // Since 2.10.0 (spec 214): the task's readable number (#123) in its sequence; null with task_numbers off.
            'number' => $task->getNumber(),
            // Since 2.16.0 (spec 234, FR-018): the optimistic-lock revision (`If-Match` / `base_revision` on update).
            'revision'         => $task->getRevision(),
            'name'             => $task->getTitle(),
            'text_content'     => $description,
            'description'      => $description,
            'status'           => $column instanceof BoardColumn ? $this->status($column, $columns) : null,
            'orderindex'       => (string) $task->getPosition(),
            'date_created'     => $this->ms($task->getCreatedAt()),
            'date_updated'     => $this->ms($task->getUpdatedAt()),
            'date_closed'      => $this->ms($task->getCompletedAt()),
            'date_done'        => $this->ms($task->getCompletedAt()),
            'archived'         => $task->isArchived(),
            'creator'          => $this->user($page->profileOf($task->getCreator())),
            'assignees'        => $this->members($task, $page, TaskMemberRole::Assignee),
            'group_assignees'  => [],
            'watchers'         => $this->members($task, $page, TaskMemberRole::Watcher),
            'checklists'       => $this->checklists($task, $page),
            'tags'             => array_map(static fn (string $tag): array => ['name' => $tag, 'tag_fg' => null, 'tag_bg' => null], $task->getTags()),
            'parent'           => $task->getParent()?->getId(),
            'top_level_parent' => null,
            'priority'         => $this->priority($task->getPriority()),
            'due_date'         => $this->ms($task->getDueAt()),
            'start_date'       => $this->ms($task->getStartAt()),
            'points'           => null,
            'time_estimate'    => $task->getEstimatedMinutes() === null ? null : (string) ($task->getEstimatedMinutes() * 60000),
            'time_spent'       => $spent,
            'custom_fields'    => $this->fieldValues($task, $page),
            'dependencies'     => $this->dependencies($task, $page, false),
            'linked_tasks'     => $this->dependencies($task, $page, true),
            'team_id'          => $space->teamId,
            'url'              => null,
            'list'             => ['id' => $board->getId(), 'name' => $board->getName(), 'access' => true],
            'project'          => self::HIDDEN_FOLDER,
            'folder'           => $this->folderRef($board),
            'space'            => ['id' => $space->id],
            // Since 2.18.0 (Beacon spec 234): the API ids of the creator and of the last recorded writer (null: unknown or
            // not shown to this viewer), where the last write came from and the host's label of its actor (a token's name).
            'created_by' => $page->profileOf($task->getCreator())?->id,
            'updated_by' => $updatedBy?->id,
            'channel'    => $task->getChannel()?->value,
            // Audit PS-M1 (FR-010): the label names its writer; only when the host shows that person to this viewer.
            'token_name' => $updatedBy instanceof TaskApiUser ? $task->getActorLabel() : null,
        ];
        if ($page->markdown) {
            $data['markdown_description'] = $description;
        }

        return $data;
    }

    /**
     * A page of tasks: `{tasks, last_page}`.
     *
     * @return array<string, mixed>
     */
    public function taskPage(TaskApiTaskPage $page): array
    {
        return ['tasks' => array_map(fn (Task $task): array => $this->task($task, $page), $page->tasks), 'last_page' => $page->lastPage];
    }

    /**
     * @param TaskApiUser|null $updatedBy since 2.18.0: who wrote or last edited it, as the viewer may see them
     *
     * @return array<string, mixed>
     */
    public function comment(TaskComment $comment, ?TaskApiUser $author, ?TaskApiUser $updatedBy = null): array
    {
        return [
            'id'           => $comment->getId(),
            'comment'      => [['text' => $comment->getBody()]],
            'comment_text' => $comment->getBody(),
            'user'         => $this->user($author),
            'resolved'     => false,
            'assignee'     => null,
            'assigned_by'  => null,
            'reactions'    => [],
            'date'         => $this->ms($comment->getCreatedAt()),
            'reply_count'  => 0,
            // Since 2.18.0 (Beacon spec 234): as on a task.
            'created_by' => $author?->id,
            'updated_by' => $updatedBy?->id,
            'channel'    => $comment->getChannel()?->value,
            // Audit PS-M1 (FR-010): only when the host shows its writer to this viewer.
            'token_name' => $updatedBy instanceof TaskApiUser ? $comment->getActorLabel() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function field(TaskSyncField $field): array
    {
        return [
            'id'               => $field->getId(),
            'name'             => $field->getName(),
            'type'             => $field->getRemoteType(),
            'type_config'      => $field->getConfig() + ($field->getChoices() !== [] ? ['options' => $field->getChoices()] : []),
            'date_created'     => $this->ms($field->getUpdatedAt()),
            'hide_from_guests' => false,
            'required'         => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function timeEntry(TaskApiTimeEntry $entry, string $teamId): array
    {
        return [
            'id'   => $entry->id,
            'task' => $entry->taskId === null ? null : ['id' => $entry->taskId],
            'wid'  => $teamId,
            'user' => ['id' => $entry->userApiId],
            // Since 2.12.0: the entry's flag; an unset one is false, as before.
            'billable'    => $entry->billable ?? false,
            'start'       => $this->ms($entry->start),
            'end'         => (string) ((int) $this->ms($entry->start) + $entry->durationSeconds * 1000),
            'duration'    => (string) ($entry->durationSeconds * 1000),
            'description' => (string) $entry->description,
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    public function user(?TaskApiUser $user): ?array
    {
        return $user === null ? null : [
            'id'             => $user->id,
            'username'       => $user->username,
            'email'          => $user->email,
            'color'          => $user->color,
            'initials'       => self::initials($user->username),
            'profilePicture' => $user->profilePicture,
        ];
    }

    /** ClickUp's initials: the first letter of the first two words, upper case. */
    public static function initials(string $name): string
    {
        $initials = '';
        foreach (array_slice(preg_split('/[\s._@-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $initials;
    }

    /**
     * @param list<BoardColumn> $columns
     *
     * @return array<string, mixed>
     */
    private function status(BoardColumn $column, array $columns): array
    {
        $index = 0;
        foreach ($columns as $position => $candidate) {
            if ($candidate === $column) {
                $index = $position;
            }
        }
        $type = match (true) {
            $index === array_key_last($columns) => 'closed',
            $index === 0                        => 'open',
            default                             => 'custom',
        };

        // Since 2.16.0 (spec 234, FR-001 / FR-014): `humans_only`.
        return ['id' => $column->getId(), 'status' => $column->getName(), 'color' => $column->getColor(), 'orderindex' => $index, 'type' => $type, 'icon' => $column->getIcon(), 'humans_only' => $column->isHumansOnly()];
    }

    /**
     * @return array<string, string>
     */
    private function priority(TaskPriority $priority): array
    {
        [$id, $color] = self::PRIORITIES[$priority->value];

        return ['id' => $id, 'priority' => $priority->value, 'color' => $color, 'orderindex' => $id];
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function members(Task $task, TaskApiTaskPage $page, TaskMemberRole $role): array
    {
        $members = [];
        foreach ($task->getMembers() as $member) {
            $profile = $member->getMemberRole() === $role ? $this->user($page->profileOf($member->getUser())) : null;
            if ($profile !== null) {
                $members[] = $profile;
            }
        }

        return $members;
    }

    /**
     * Checklist items are subtasks tagged `checklist:<name>` (as a provider sync imports them): one checklist per name.
     *
     * @return list<array<string, mixed>>
     */
    private function checklists(Task $task, TaskApiTaskPage $page): array
    {
        $checklists = [];
        foreach ($page->subtasks[$task->getId()] ?? [] as $item) {
            foreach ($item->getTags() as $tag) {
                if (str_starts_with($tag, self::CHECKLIST_TAG)) {
                    $checklists[substr($tag, 10)][] = $item;
                }
            }
        }
        $answer = [];
        foreach ($checklists as $name => $items) {
            $resolved = count(array_filter($items, static fn (Task $item): bool => $item->isCompleted()));
            $answer[] = [
                'id'         => self::checklistId($task->getId(), (string) $name),
                'task_id'    => $task->getId(),
                'name'       => (string) $name,
                'orderindex' => count($answer),
                'resolved'   => $resolved,
                'unresolved' => count($items) - $resolved,
                'items'      => array_map(fn (Task $item): array => [
                    'id'         => $item->getId(),
                    'name'       => $item->getTitle(),
                    'orderindex' => $item->getPosition(),
                    'assignee'   => $this->members($item, $page, TaskMemberRole::Assignee)[0] ?? null,
                    'resolved'   => $item->isCompleted(),
                    'parent'     => null,
                    'children'   => [],
                ], $items),
            ];
        }

        return $answer;
    }

    /**
     * One checklist of the task by its name; one with no item yet is empty (it is not stored until it has one).
     *
     * @return array<string, mixed>
     */
    public function checklist(Task $task, TaskApiTaskPage $page, string $name): array
    {
        foreach ($this->checklists($task, $page) as $checklist) {
            if ($checklist['name'] === $name) {
                return $checklist;
            }
        }

        return ['id' => self::checklistId($task->getId(), $name), 'task_id' => $task->getId(), 'name' => $name, 'orderindex' => 0, 'resolved' => 0, 'unresolved' => 0, 'items' => []];
    }

    /** A checklist has no row of its own: its id names the task and the checklist's name. */
    public static function checklistId(string $taskId, string $name): string
    {
        return 'cl_' . rtrim(strtr(base64_encode($taskId . '|' . $name), '+/', '-_'), '=');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fieldValues(Task $task, TaskApiTaskPage $page): array
    {
        return array_map(static fn ($value): array => [
            'id'    => $value->getField()->getId(),
            'name'  => $value->getField()->getName(),
            'type'  => $value->getField()->getRemoteType(),
            'value' => $value->getValue(),
        ], $page->fieldValues[$task->getId()] ?? []);
    }

    /**
     * ClickUp's dependencies (`task_id` waits on `depends_on`) or, for `$linked`, its links (related, duplicates).
     *
     * @return list<array<string, mixed>>
     */
    private function dependencies(Task $task, TaskApiTaskPage $page, bool $linked): array
    {
        $answer = [];
        foreach ($page->dependencies as $dependency) {
            $source = $dependency->getSourceTask()->getId();
            $target = $dependency->getTargetTask()->getId();
            if ($source !== $task->getId() && $target !== $task->getId()) {
                continue;
            }
            $type = $dependency->getDependencyType();
            if ($linked !== in_array($type, [TaskDependencyType::Related, TaskDependencyType::Duplicates], true)) {
                continue;
            }
            if ($linked) {
                $answer[] = ['task_id' => $task->getId(), 'link_id' => $source === $task->getId() ? $target : $source, 'date_created' => $this->ms($dependency->getCreatedAt())];
                continue;
            }
            [$waiting, $on] = $type === TaskDependencyType::Blocks ? [$target, $source] : [$source, $target];
            $answer[]       = ['task_id' => $waiting, 'depends_on' => $on, 'type' => $waiting === $task->getId() ? 1 : 0, 'date_created' => $this->ms($dependency->getCreatedAt())];
        }

        return $answer;
    }

    /**
     * The board's columns by position: a reorder within the same request leaves the loaded collection in its old order.
     *
     * @return list<BoardColumn>
     */
    private function ordered(TaskBoard $board): array
    {
        $columns = array_values($board->getColumns()->toArray());
        usort($columns, static fn (BoardColumn $a, BoardColumn $b): int => $a->getPosition() <=> $b->getPosition());

        return $columns;
    }

    private function ms(?DateTimeInterface $date): ?string
    {
        return $date?->format('Uv');
    }
}
