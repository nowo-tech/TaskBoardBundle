<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Operation;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Hook\EmptyTaskApiMembers;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiMembersInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiPlanGateInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntriesInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiUsersInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiWorkspaceInterface;
use Nowo\TaskBoardBundle\Api\Repository\TaskApiQueryRepository;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiBoardList;
use Nowo\TaskBoardBundle\Api\Value\TaskApiCommentPage;
use Nowo\TaskBoardBundle\Api\Value\TaskApiMember;
use Nowo\TaskBoardBundle\Api\Value\TaskApiSpace;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskFilter;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskPage;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTeam;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimePage;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeQuery;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUser;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Number\TaskNumberLedger;
use Nowo\TaskBoardBundle\Number\TaskNumberScope;
use Nowo\TaskBoardBundle\Search\TaskTextSearchInterface;
use Nowo\TaskBoardBundle\Support\TextFold;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\ValueObject\TaskTextQuery;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function ltrim;
use function mb_strlen;
use function preg_match;
use function spl_object_id;
use function str_starts_with;
use function trim;

/**
 * Since 2.2.0 (spec 213): what the API reads, without HTTP: each method takes the principal and applies every check, so
 * an in-process caller gets exactly what a request would.
 */
final readonly class TaskApiReadOperations
{
    /** Since 2.16.0: at most this many members answer a name lookup. */
    public const MEMBER_MATCHES = 20;

    /** Comments per page, as ClickUp. */
    public const COMMENTS_PER_PAGE = 25;

    public function __construct(
        private TaskApiPlanGateInterface $planGate,
        private TaskApiWorkspaceInterface $workspace,
        private TaskApiUsersInterface $users,
        private TaskApiGuard $guard,
        private TaskApiQueryRepository $queries,
        private TaskApiTimeEntriesInterface $timeEntries,
        // Since 2.10.0 (spec 214): `task_numbers.enabled` (null when off): a task looked up by its number.
        private ?TaskNumberLedger $numbers = null,
        private ?TaskNumberScope $numberScope = null,
        // Since 2.16.0 (spec 234, FR-025): the members of a team.
        private TaskApiMembersInterface $members = new EmptyTaskApiMembers(),
        // Since 2.16.0 (spec 234, FR-026): the text search, and `search.max_matches`.
        private ?TaskTextSearchInterface $search = null,
        private int $searchMaxMatches = 500,
    ) {
    }

    /**
     * Since 2.16.0 (spec 234, FR-025): the members of one of the viewer's teams; any other team is 404 before the host is
     * asked. The e-mail is kept only on the viewer's own row.
     *
     * @throws TaskApiRefusal
     *
     * @return list<TaskApiMember>
     */
    public function teamMembers(TaskApiPrincipal $principal, string $teamId): array
    {
        $this->requireTeam($principal, $teamId);

        return $this->membersOf($principal, $teamId);
    }

    /**
     * Since 2.16.0 (spec 234, FR-025): the members of one of the viewer's teams whose name, or a word of it, starts with
     * `$name`, case and accents aside; at most {@see self::MEMBER_MATCHES}. A lookup only: nothing is ever assigned by name.
     *
     * @throws TaskApiRefusal
     *
     * @return list<TaskApiMember>
     */
    public function findMembers(TaskApiPrincipal $principal, string $teamId, string $name): array
    {
        $this->requireTeam($principal, $teamId);
        if (mb_strlen(trim($name)) > 128) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid name: at most 128 characters');
        }
        $found = [];
        foreach ($this->membersOf($principal, $teamId) as $member) {
            if (TextFold::prefixMatches($name, $member->name)) {
                $found[] = $member;
                if (count($found) === self::MEMBER_MATCHES) {
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<TaskApiMember>
     */
    private function membersOf(TaskApiPrincipal $principal, string $teamId): array
    {
        try {
            $own = $this->users->apiId($principal->user);
        } catch (TaskApiRefusal) {
            $own = null;
        }

        return array_values(array_map(static fn (TaskApiMember $member): TaskApiMember => $own !== null && $member->id === $own ? $member : $member->withoutEmail(), $this->members->members($principal, $teamId)));
    }

    /**
     * @throws TaskApiRefusal
     */
    public function me(TaskApiPrincipal $principal): TaskApiUser
    {
        $this->requireIdentity($principal);

        return $this->users->profiles([$principal->user], $principal)[$this->users->apiId($principal->user)] ?? throw TaskApiRefusal::notFound();
    }

    /**
     * @throws TaskApiRefusal
     *
     * @return list<TaskApiTeam>
     */
    public function teams(TaskApiPrincipal $principal): array
    {
        $this->requireIdentity($principal);

        return $this->workspace->teams($principal);
    }

    /**
     * Since 2.16.0 (spec 234, FR-025): the viewer's teams, each with its members (`members: []` without `api.hooks.members`).
     *
     * @throws TaskApiRefusal
     *
     * @return list<array{team: TaskApiTeam, members: list<TaskApiMember>}>
     */
    public function teamsWithMembers(TaskApiPrincipal $principal): array
    {
        return array_map(fn (TaskApiTeam $team): array => ['team' => $team, 'members' => $this->membersOf($principal, $team->id)], $this->teams($principal));
    }

    /**
     * The spaces of one of the token's teams that where reaches and whose plan holds the module.
     *
     * @throws TaskApiRefusal
     *
     * @return list<TaskApiSpace>
     */
    public function spaces(TaskApiPrincipal $principal, string $teamId): array
    {
        $this->requireTeam($principal, $teamId);

        return array_values(array_filter($this->workspace->spaces($principal, $teamId), fn (TaskApiSpace $space): bool => $this->guard->lists($principal, $space)));
    }

    /**
     * @throws TaskApiRefusal
     */
    public function space(TaskApiPrincipal $principal, string $spaceId): TaskApiSpace
    {
        return $this->guard->space($principal, $spaceId, TaskApiCapability::ReadOwn);
    }

    /**
     * @throws TaskApiRefusal
     */
    public function boards(TaskApiPrincipal $principal, string $spaceId): TaskApiBoardList
    {
        $space  = $this->guard->space($principal, $spaceId, TaskApiCapability::ReadOwn);
        $boards = $this->guard->visibleBoards($principal, $space->tenantKey);

        return new TaskApiBoardList($space, $boards, $this->queries->countByBoard($this->ids($boards), $this->owner($principal)));
    }

    /**
     * @throws TaskApiRefusal
     */
    public function board(TaskApiPrincipal $principal, string $boardId): TaskApiBoardList
    {
        $board = $this->guard->board($principal, $boardId, TaskApiCapability::ReadOwn);

        return new TaskApiBoardList($this->spaceOf($principal, $board), [$board], $this->queries->countByBoard([$board->getId()], $this->owner($principal)));
    }

    /**
     * @throws TaskApiRefusal
     */
    public function tasks(TaskApiPrincipal $principal, string $boardId, TaskApiTaskFilter $filter): TaskApiTaskPage
    {
        $board = $this->guard->board($principal, $boardId, TaskApiCapability::ReadOwn);

        return $this->page($principal, [$board], $filter);
    }

    /**
     * Tasks across the boards of a team the token reaches; `space_ids[]` and `list_ids[]` narrow them.
     *
     * @throws TaskApiRefusal
     */
    public function teamTasks(TaskApiPrincipal $principal, string $teamId, TaskApiTaskFilter $filter): TaskApiTaskPage
    {
        $boards = [];
        foreach ($this->spaces($principal, $teamId) as $space) {
            if ($filter->spaceIds !== [] && !in_array($space->id, $filter->spaceIds, true)) {
                continue;
            }
            foreach ($this->guard->visibleBoards($principal, $space->tenantKey) as $board) {
                if ($filter->listIds === [] || in_array($board->getId(), $filter->listIds, true)) {
                    $boards[] = $board;
                }
            }
        }

        return $this->page($principal, $boards, $filter);
    }

    /**
     * @throws TaskApiRefusal
     */
    public function task(TaskApiPrincipal $principal, string $taskId, bool $markdown = false): TaskApiTaskPage
    {
        $task = $this->guard->task($principal, $taskId, TaskApiCapability::ReadOwn);

        return $this->context($principal, $this->queries->load([$task->getId()]), true, $markdown);
    }

    /**
     * Since 2.16.0 (spec 234): tasks the caller already reached (a bulk update's), in the given order, with what serializing
     * them needs, in a fixed number of queries.
     *
     * @param list<string> $taskIds
     */
    public function taskList(TaskApiPrincipal $principal, array $taskIds): TaskApiTaskPage
    {
        return $this->context($principal, $this->queries->load($taskIds), true, false);
    }

    /**
     * Since 2.10.0 (spec 214): `GET /v2/task/{number}?custom_task_ids=true` — the task carrying `$number` (`123` or `#123`)
     * in the number sequence of a space (`$spaceId`, with `task_numbers.scope: tenant` and a space with a tenant) or of a list
     * (`$listId`, any scope). ClickUp's custom task ids are unique in a workspace; the numbers here are unique only within their
     * sequence, hence the space or list. A number a moved task left behind is not found, nor is any without task numbers.
     *
     * @throws TaskApiRefusal 400 `INPUT_002` without a space or list that names a sequence; 404 as for any task
     */
    public function taskByNumber(TaskApiPrincipal $principal, string $number, ?string $spaceId, ?string $listId, bool $markdown = false): TaskApiTaskPage
    {
        if ($listId !== null && $listId !== '') {
            $board    = $this->guard->board($principal, $listId, TaskApiCapability::ReadOwn);
            $scopeKey = $this->numberScope?->keyOf($board);
        } elseif ($spaceId !== null && $spaceId !== '') {
            $space = $this->guard->space($principal, $spaceId, TaskApiCapability::ReadOwn);
            if ($this->numberScope instanceof TaskNumberScope && (!$this->numberScope->isPerTenant() || $space->tenantKey === null || $space->tenantKey === '')) {
                throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid space_id: numbers are per list here, give list_id');
            }
            $scopeKey = $space->tenantKey;
        } else {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid custom_task_ids: give space_id or list_id');
        }

        if (preg_match('/^#?[1-9][0-9]{0,8}$/D', $number) !== 1 || $scopeKey === null || !$this->numbers instanceof TaskNumberLedger || !$this->numberScope instanceof TaskNumberScope) {
            throw TaskApiRefusal::notFound();
        }
        $wanted = (int) ltrim($number, '#');
        $task   = $this->numbers->find($scopeKey, $wanted);
        // Audit JY-I1: only the task's current number in its current sequence; a number it left behind is not found.
        if (!$task instanceof Task || $task->getNumber() !== $wanted || $this->numberScope->keyOf($task->getBoard()) !== $scopeKey) {
            throw TaskApiRefusal::notFound();
        }

        return $this->task($principal, $task->getId(), $markdown);
    }

    /**
     * Newest first, 25 per page; `$start` and `$startId` name the oldest comment of the previous page.
     *
     * @throws TaskApiRefusal
     */
    public function comments(TaskApiPrincipal $principal, string $taskId, ?DateTimeImmutable $start, ?string $startId): TaskApiCommentPage
    {
        $task     = $this->guard->task($principal, $taskId, TaskApiCapability::ReadOwn);
        $comments = $this->queries->comments($task, $start, $startId, self::COMMENTS_PER_PAGE);
        $authors  = [];
        $updaters = [];
        $people   = [];
        foreach ($comments as $comment) {
            // Since 2.19.0 (Beacon spec 234): the last writer too, in the same profile call.
            foreach ([$comment->getAuthor(), $comment->getUpdatedBy()] as $person) {
                if ($person !== null) {
                    $people[spl_object_id($person)] = $person;
                }
            }
        }
        $profiles  = $this->profiles($principal, array_values($people));
        $profileOf = static function (?object $person) use ($profiles): ?TaskApiUser {
            $id = $person === null ? null : $profiles['ids'][spl_object_id($person)] ?? null;

            return $id === null ? null : $profiles['profiles'][$id] ?? null;
        };
        foreach ($comments as $comment) {
            $author = $profileOf($comment->getAuthor());
            if ($author instanceof TaskApiUser) {
                $authors[$comment->getId()] = $author;
            }
            $updater = $profileOf($comment->getUpdatedBy());
            if ($updater instanceof TaskApiUser) {
                $updaters[$comment->getId()] = $updater;
            }
        }

        return new TaskApiCommentPage($comments, $authors, $updaters);
    }

    /**
     * Everyone attached to the task, once, as the viewer may see them.
     *
     * @throws TaskApiRefusal
     *
     * @return list<TaskApiUser>
     */
    public function members(TaskApiPrincipal $principal, string $taskId): array
    {
        $task  = $this->guard->task($principal, $taskId, TaskApiCapability::ReadEverything);
        $users = [];
        foreach ($task->getMembers() as $member) {
            $users[spl_object_id($member->getUser())] = $member->getUser();
        }

        return array_values($this->profiles($principal, array_values($users))['profiles']);
    }

    /**
     * @throws TaskApiRefusal
     *
     * @return list<string>
     */
    public function spaceTags(TaskApiPrincipal $principal, string $spaceId): array
    {
        $space = $this->guard->space($principal, $spaceId, TaskApiCapability::ReadEverything);

        return $this->queries->tagsOf($this->ids($this->guard->visibleBoards($principal, $space->tenantKey)));
    }

    /**
     * The fields a provider sync imported for the board; none without one.
     *
     * @throws TaskApiRefusal
     *
     * @return list<TaskSyncField>
     */
    public function boardFields(TaskApiPrincipal $principal, string $boardId): array
    {
        return $this->queries->fieldsOf($this->guard->board($principal, $boardId, TaskApiCapability::ReadEverything)->getId());
    }

    /**
     * The host's time store decides whose time the token sees; a named task must be within reach first.
     *
     * @throws TaskApiRefusal
     */
    public function timeEntries(TaskApiPrincipal $principal, TaskApiTimeQuery $query): TaskApiTimePage
    {
        $this->requireTeam($principal, $query->teamId);
        if ($query->taskId !== null) {
            $this->guard->task($principal, $query->taskId, TaskApiCapability::ReadOwn);
        }

        return $this->timeEntries->list($principal, $query);
    }

    /**
     * @param list<TaskBoard> $boards already checked
     *
     * @throws TaskApiRefusal
     */
    private function page(TaskApiPrincipal $principal, array $boards, TaskApiTaskFilter $filter): TaskApiTaskPage
    {
        $assignees = [];
        foreach ($filter->assigneeIds as $apiId) {
            $user = $this->users->find($apiId);
            if ($user !== null) {
                $assignees[] = $user;
            }
        }
        $ids     = $this->ids($boards);
        $matches = null;
        if ($filter->query !== null && $ids !== []) {
            // Since 2.16.0 (spec 234, FR-026): only within the boards already checked, before any page or limit; the
            // other filters and My tasks apply to the matches in SQL.
            if (!$this->search instanceof TaskTextSearchInterface) {
                // The container always wires one.
                throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid query: text search is not available'); // @codeCoverageIgnore
            }
            $matches = $this->search->matchingTaskIds(new TaskTextQuery($filter->query, $ids, $this->searchMaxMatches));
            if (count($matches) > $this->searchMaxMatches) {
                throw new TaskApiRefusal(400, 'SEARCH_001', 'Too many matches: narrow the query');
            }
        }
        $result = $this->queries->taskPage($ids, $filter, $assignees, $this->owner($principal), $matches);

        return $this->context($principal, $result['tasks'], $result['lastPage'], $filter->markdown);
    }

    /**
     * Everything the tasks are shown with, in a fixed number of queries and one call to each hook.
     *
     * @param list<Task> $tasks
     */
    private function context(TaskApiPrincipal $principal, array $tasks, bool $lastPage, bool $markdown): TaskApiTaskPage
    {
        $ids      = array_map(static fn (Task $task): string => $task->getId(), $tasks);
        $subtasks = [];
        foreach ($this->queries->subtasksOf($ids) as $subtask) {
            // Audit IA-M2: only the subtasks the token may open; a checklist item of a task it reaches is part of that task
            // (owner: My tasks manages the checklists of its own tasks).
            if ($this->isChecklistItem($subtask) || $this->guard->canSeeTask($principal, $subtask)) {
                $subtasks[(string) $subtask->getParent()?->getId()][] = $subtask;
            }
        }
        // Audit IA-I7: a relation is shown only when the token may open the other task too.
        $dependencies = array_values(array_filter($this->queries->dependenciesOf($ids), function (TaskDependency $dependency) use ($principal, $ids): bool {
            foreach ([$dependency->getSourceTask(), $dependency->getTargetTask()] as $end) {
                if (!in_array($end->getId(), $ids, true) && !$this->guard->canSeeTask($principal, $end)) {
                    return false;
                }
            }

            return true;
        }));
        $spaces = [];
        $users  = [];
        foreach ($tasks as $task) {
            $board = $task->getBoard();
            $spaces[$board->getId()] ??= $this->spaceOf($principal, $board);
            // Since 2.19.0 (Beacon spec 234): the last writer too (`updated_by`).
            foreach ([$task->getCreator(), $task->getUpdatedBy()] as $person) {
                if ($person !== null) {
                    $users[spl_object_id($person)] = $person;
                }
            }
            foreach ([$task, ...$subtasks[$task->getId()] ?? []] as $shown) {
                foreach ($shown->getMembers() as $member) {
                    $users[spl_object_id($member->getUser())] = $member->getUser();
                }
            }
        }
        $profiles = $this->profiles($principal, array_values($users));

        return new TaskApiTaskPage(
            tasks: $tasks,
            lastPage: $lastPage,
            spaces: $spaces,
            profiles: $profiles['profiles'],
            userIds: $profiles['ids'],
            dependencies: $dependencies,
            subtasks: $subtasks,
            manualMinutes: $this->queries->manualMinutesOf($ids),
            fieldValues: $this->queries->fieldValuesOf($ids),
            markdown: $markdown,
        );
    }

    /**
     * @param list<object> $users
     *
     * @return array{profiles: array<string, TaskApiUser>, ids: array<int, string>}
     */
    private function profiles(TaskApiPrincipal $principal, array $users): array
    {
        if ($users === []) {
            return ['profiles' => [], 'ids' => []];
        }
        $profiles = $this->users->profiles($users, $principal);
        $ids      = [];
        foreach ($users as $user) {
            try {
                $ids[spl_object_id($user)] = $this->users->apiId($user);
            } catch (TaskApiRefusal) {
                // A person the host does not know is not shown.
            }
        }

        return ['profiles' => $profiles, 'ids' => $ids];
    }

    private function isChecklistItem(Task $task): bool
    {
        foreach ($task->getTags() as $tag) {
            if (str_starts_with($tag, 'checklist:')) {
                return true;
            }
        }

        return false;
    }

    private function spaceOf(TaskApiPrincipal $principal, TaskBoard $board): TaskApiSpace
    {
        // The guard already asked the host for this tenant: a board without a space is out of reach.
        return $this->workspace->spaceOfTenant($principal, $board->getTenantKey()) ?? throw TaskApiRefusal::notFound();
    }

    /**
     * @throws TaskApiRefusal
     */
    private function requireTeam(TaskApiPrincipal $principal, string $teamId): void
    {
        $this->requireIdentity($principal);
        foreach ($this->workspace->teams($principal) as $team) {
            if ($team->id === $teamId) {
                return;
            }
        }

        throw TaskApiRefusal::notFound();
    }

    /**
     * Every level may read who it is, so only the plan is asked.
     *
     * @throws TaskApiRefusal
     */
    private function requireIdentity(TaskApiPrincipal $principal): void
    {
        if (!$this->planGate->allowsTasks($principal->user, null)) {
            throw TaskApiRefusal::planRefused();
        }
    }

    private function owner(TaskApiPrincipal $principal): ?object
    {
        return $principal->level === TaskApiLevel::MyTasks ? $principal->user : null;
    }

    /**
     * @param list<TaskBoard> $boards
     *
     * @return list<string>
     */
    private function ids(array $boards): array
    {
        return array_map(static fn (TaskBoard $board): string => $board->getId(), $boards);
    }
}
