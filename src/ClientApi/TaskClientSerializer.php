<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiChecklistOperations;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskPage;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUser;
use Nowo\TaskBoardBundle\ClientApi\Text\TaskTextTokens;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolver;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkVisibilityInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function intdiv;
use function mb_strtoupper;
use function mb_substr;
use function preg_match;
use function str_starts_with;
use function trim;
use function usort;

/**
 * Since 3.4.0 (client API v1, §4.4 / §4.5 of the Beacon Desktop tasks design): the JSON of a task card, a task's detail,
 * a board and a person, for the reader of the request (R2). People are the host's public ids with the name and avatar
 * the host shows this reader (never an e-mail); another task (parent, subtask, dependency) is shown only when the reader
 * may open it, else `{visible: false}` with nothing else; a host-object link only when
 * {@see TaskObjectLinkVisibilityInterface} lets the reader see it (deny by default); text as tokens/1. Dates RFC 3339
 * in UTC (`Z`). Stateless.
 *
 * @internal
 */
final readonly class TaskClientSerializer
{
    public function __construct(
        private TaskTextTokens $text,
        private TaskApiGuard $guard,
        private TaskBoardResourceAccessChecker $access,
        private TaskObjectLinkResolver $objectLinks,
        private ?TaskObjectLinkVisibilityInterface $linkVisibility = null,
        private ?UrlGeneratorInterface $urls = null,
        private ?string $taskRoute = null,
    ) {
    }

    /**
     * A person as the reader may see them: `{id, name, initials, avatarUrl}`; null when the host leaves them out.
     *
     * @return array{id: string, name: string, initials: string, avatarUrl: string|null}|null
     */
    public function user(?TaskApiUser $user): ?array
    {
        if (!$user instanceof TaskApiUser) {
            return null;
        }
        $initials = '';
        foreach (array_values(array_filter(explode(' ', trim($user->username)), static fn (string $word): bool => $word !== '')) as $index => $word) {
            if ($index < 2) {
                $initials .= mb_strtoupper(mb_substr($word, 0, 1));
            }
        }

        return ['id' => $user->id, 'name' => $user->username, 'initials' => $initials, 'avatarUrl' => TaskTextTokens::safeUrl($user->profilePicture)];
    }

    /**
     * A column: `{id, name, color, icon, type, humansOnly, position}`.
     *
     * @param list<BoardColumn> $columns the board's columns in order
     *
     * @return array<string, mixed>
     */
    public function column(BoardColumn $column, array $columns): array
    {
        return [
            'id'         => $column->getId(),
            'name'       => $column->getName(),
            'color'      => $column->getColor(),
            'icon'       => $column->getIcon(),
            'type'       => ColumnTypes::of($column, $columns)->value,
            'humansOnly' => $column->isHumansOnly(),
        ];
    }

    /**
     * A board of the list: `{id, title, icon, color, archived, group, columns, counts, permissions}`.
     *
     * @param array{type: string, id: string, label: string}|null $group
     *
     * @return array<string, mixed>
     */
    public function board(TaskBoard $board, object $reader, int $openCount, ?array $group): array
    {
        $columns = ColumnTypes::ordered($board);
        $canEdit = $this->access->canEditBoard($reader, $board);

        return [
            'id'          => $board->getId(),
            'title'       => $board->getName(),
            'icon'        => $board->getIcon(),
            'color'       => null,
            'archived'    => false,
            'group'       => $group,
            'columns'     => array_map(fn (BoardColumn $column): array => $this->column($column, $columns), $columns),
            'counts'      => ['open' => $openCount],
            'permissions' => ['canCreate' => $canEdit, 'canMove' => $canEdit],
        ];
    }

    /**
     * A task card (lists and board columns).
     *
     * @param array<string, int> $openQuestions by task id
     * @param array<string, true> $waiting task ids with a question waiting for the reader
     * @param array<string, int> $commentCounts by task id
     *
     * @return array<string, mixed>
     */
    public function card(Task $task, TaskApiTaskPage $page, array $openQuestions = [], array $waiting = [], array $commentCounts = []): array
    {
        $board   = $task->getBoard();
        $columns = ColumnTypes::ordered($board);
        $column  = $task->getColumn();
        $subtask = ['total' => 0, 'done' => 0];
        $items   = ['total' => 0, 'done' => 0];
        // The page's subtasks are the open (not archived) ones.
        foreach ($page->subtasks[$task->getId()] ?? [] as $child) {
            $done = $child->isCompleted() ? 1 : 0;
            if (TaskApiChecklistOperations::checklistNameOf($child) !== null) {
                $items = ['total' => $items['total'] + 1, 'done' => $items['done'] + $done];
            } else {
                $subtask = ['total' => $subtask['total'] + 1, 'done' => $subtask['done'] + $done];
            }
        }
        $spentSeconds = $task->getTotalTimeSeconds() + ($page->manualMinutes[$task->getId()] ?? 0) * 60;

        return [
            'id'               => $task->getId(),
            'number'           => $task->getNumber(),
            'title'            => $task->getTitle(),
            'boardId'          => $board->getId(),
            'column'           => $column instanceof BoardColumn ? ['id' => $column->getId(), 'name' => $column->getName(), 'icon' => $column->getIcon(), 'type' => ColumnTypes::of($column, $columns)->value] : null,
            'priority'         => $task->getPriority()->value,
            'color'            => $task->getColor(),
            'tags'             => array_values(array_filter($task->getTags(), static fn (string $tag): bool => !str_starts_with($tag, TaskApiChecklistOperations::TAG_PREFIX))),
            'startAt'          => self::date($task->getStartAt()),
            'dueAt'            => self::date($task->getDueAt()),
            'allDay'           => self::allDay($task),
            'assignees'        => $this->people($task, $page, TaskMemberRole::Assignee),
            'subtaskSummary'   => $subtask,
            'checklistSummary' => $items,
            'openQuestions'    => $openQuestions[$task->getId()] ?? 0,
            'waitingForMe'     => isset($waiting[$task->getId()]),
            'comments'         => $commentCounts[$task->getId()] ?? 0,
            'estimateMinutes'  => $task->getEstimatedMinutes(),
            'timeSpentMinutes' => intdiv($spentSeconds, 60),
            'archived'         => $task->isArchived(),
            'completed'        => $task->isCompleted(),
            'revision'         => $task->getRevision(),
            'updatedAt'        => self::date($task->getUpdatedAt()),
        ];
    }

    /**
     * A task's detail: the card plus its text, parent, members, checklists, links, dependencies, provenance and what the
     * reader may do.
     *
     * @param array<string, int> $openQuestions
     * @param array<string, true> $waiting
     * @param array<string, int> $commentCounts
     * @param list<TaskDependency> $dependencies every dependency of the task (the other end shown only when visible)
     *
     * @return array<string, mixed>
     */
    public function detail(Task $task, TaskApiTaskPage $page, TaskClientCall $call, array $openQuestions = [], array $waiting = [], array $commentCounts = [], array $dependencies = []): array
    {
        $reader    = $call->principal->user;
        $parent    = $task->getParent();
        $canEdit   = $this->access->canEditTask($reader, $task);
        $writes    = $call->principal->level->value === 'tasks';
        $checklist = [];
        foreach ($page->subtasks[$task->getId()] ?? [] as $child) {
            $name = TaskApiChecklistOperations::checklistNameOf($child);
            if ($name === null || $child->isArchived()) {
                continue;
            }
            $checklist[$name]['name']    = $name;
            $checklist[$name]['items'][] = ['id' => $child->getId(), 'title' => $child->getTitle(), 'done' => $child->isCompleted(), 'revision' => $child->getRevision()];
        }
        $members = [];
        foreach ($task->getMembers() as $member) {
            $profile = $this->user($page->profileOf($member->getUser()));
            if ($profile !== null) {
                $members[] = ['user' => $profile, 'role' => $member->getMemberRole()->value];
            }
        }

        return $this->card($task, $page, $openQuestions, $waiting, $commentCounts) + [
            'description'  => $this->text->of($task->getDescription(), $reader),
            'parent'       => $parent instanceof Task ? $this->otherTask($call, $parent) : null,
            'members'      => $members,
            'checklists'   => array_values($checklist),
            'links'        => array_map(fn (TaskLink $link): array => $this->link($link, $reader), self::ordered($task->getLinks()->toArray())),
            'dependencies' => $this->dependencies($task, $dependencies, $call),
            'questions'    => ['open' => $openQuestions[$task->getId()] ?? 0, 'waitingForMe' => isset($waiting[$task->getId()])],
            'createdBy'    => $this->user($page->profileOf($task->getCreator())),
            'updatedBy'    => $this->user($page->profileOf($task->getUpdatedBy())),
            'channel'      => $task->getChannel()?->value,
            'createdAt'    => self::date($task->getCreatedAt()),
            'permissions'  => [
                'canEdit'      => $writes && $canEdit,
                'canMove'      => $writes && $canEdit,
                'canComment'   => false,
                'canAssign'    => $writes && $canEdit,
                'canAsk'       => false,
                'canTrackTime' => $this->access->canTrackTime($reader, $task),
            ],
            'webUrl' => $this->webUrl($task),
        ];
    }

    /**
     * A comment: `{id, author, body (tokens/1), createdAt, editedAt, canEdit}`.
     *
     * @return array<string, mixed>
     */
    public function comment(TaskComment $comment, ?TaskApiUser $author, object $reader): array
    {
        return [
            'id'        => $comment->getId(),
            'author'    => $this->user($author),
            'body'      => $this->text->of($comment->getBody(), $reader),
            'createdAt' => self::date($comment->getCreatedAt()),
            'editedAt'  => self::date($comment->getEditedAt()),
            'canEdit'   => false,
        ];
    }

    /**
     * Another task as the reader may see it: `{id, number, title, visible: true}` or `{visible: false}`.
     *
     * @return array<string, mixed>
     */
    public function otherTask(TaskClientCall $call, Task $other): array
    {
        return $this->guard->canSeeTask($call->principal, $other)
            ? ['id' => $other->getId(), 'number' => $other->getNumber(), 'title' => $other->getTitle(), 'visible' => true]
            : ['visible' => false];
    }

    /**
     * Links in the order they were added (then by id): the same answer on every database.
     *
     * @param array<array-key, TaskLink> $links
     *
     * @return list<TaskLink>
     */
    private static function ordered(array $links): array
    {
        $links = array_values($links);
        usort($links, static fn (TaskLink $a, TaskLink $b): int => [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()]);

        return $links;
    }

    /** RFC 3339 in UTC (`Z`), or null. */
    public static function date(?DateTimeInterface $date): ?string
    {
        return $date instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : null;
    }

    /** Whether the task's dates are days (midnight UTC): the bundle's date-only dates. */
    public static function allDay(Task $task): bool
    {
        $dates = array_values(array_filter([$task->getDueAt(), $task->getStartAt()]));
        foreach ($dates as $date) {
            if (DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('H:i:s') !== '00:00:00') {
                return false;
            }
        }

        return $dates !== [];
    }

    /**
     * @return list<array{id: string, name: string, initials: string, avatarUrl: string|null}>
     */
    private function people(Task $task, TaskApiTaskPage $page, TaskMemberRole $role): array
    {
        $people = [];
        foreach ($task->getMembers() as $member) {
            if ($member->getMemberRole() === $role) {
                $profile = $this->user($page->profileOf($member->getUser()));
                if ($profile !== null) {
                    $people[] = $profile;
                }
            }
        }

        return $people;
    }

    /**
     * @return array<string, mixed>
     */
    private function link(TaskLink $link, object $reader): array
    {
        $type = $link->getLinkType();
        if ($type === TaskLinkType::Attachment) {
            // Files through the client API come with 3.5.0.
            return ['type' => $type->value, 'visible' => false];
        }
        if ($link->isHostObject()) {
            $objectType = (string) $link->getObjectType();
            $objectId   = (string) $link->getObjectId();
            if (!$this->linkVisibility instanceof TaskObjectLinkVisibilityInterface || !$this->linkVisibility->visibleTo($reader, $objectType, $objectId)) {
                return ['type' => $type->value, 'visible' => false];
            }

            return ['type' => $type->value, 'objectType' => $objectType, 'url' => TaskTextTokens::safeUrl($this->objectLinks->url($link)), 'title' => $this->objectLinks->label($link), 'visible' => true];
        }
        $url = $link->getUrl();

        return ['type' => $type->value, 'url' => preg_match('~^https?://~i', $url) === 1 ? $url : null, 'title' => $link->getLabel() ?? $url, 'visible' => true];
    }

    /**
     * @param list<TaskDependency> $dependencies
     *
     * @return list<array<string, mixed>>
     */
    private function dependencies(Task $task, array $dependencies, TaskClientCall $call): array
    {
        $shown = [];
        foreach ($dependencies as $dependency) {
            // The caller passes the dependencies touching `$task` only.
            $outgoing = $dependency->getSourceTask()->getId() === $task->getId();
            $incoming = !$outgoing;
            $other    = $outgoing ? $dependency->getTargetTask() : $dependency->getSourceTask();
            $shown[]  = ['type' => $dependency->getDependencyType()->relation($incoming), 'task' => $this->otherTask($call, $other)];
        }

        return $shown;
    }

    private function webUrl(Task $task): ?string
    {
        try {
            return $this->urls?->generate((string) $this->taskRoute, ['taskId' => $task->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (Throwable) { // @codeCoverageIgnoreStart
            // Under tenant paths the route needs the tenant's slug: no link then.
            return null;
        } // @codeCoverageIgnoreEnd
    }
}
