<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TaskBoardBundle\Api\Controller\TaskApiQuestionController;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiUsersInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiWorkspaceInterface;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiQuestionOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\Repository\TaskApiQueryRepository;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiMember;
use Nowo\TaskBoardBundle\Api\Value\TaskApiSpace;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUser;
use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientContextPolicyInterface;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientContext;
use Nowo\TaskBoardBundle\ClientApi\Text\TaskTextTokens;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\ColumnType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\TaskQuestionManager;
use Nowo\TaskBoardBundle\Question\TaskQuestionQuery;
use Nowo\TaskBoardBundle\Question\TaskQuestionSnapshot;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Search\TaskTextSearchInterface;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Support\TextFold;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;
use Nowo\TaskBoardBundle\ValueObject\TaskTextQuery;
use Symfony\Component\HttpFoundation\Request;

use function array_filter;
use function array_key_last;
use function array_map;
use function array_pad;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function in_array;
use function is_string;
use function mb_strlen;
use function mb_strtolower;
use function preg_match;
use function rawurldecode;
use function spl_object_id;
use function str_contains;
use function str_replace;
use function trim;
use function usort;

/**
 * Since 3.4.0 (client API v1, §4.2 of the Beacon Desktop tasks design): the read routes of `/task-board/v1`, for the
 * context of the request. Every list is narrowed by the context's `where`, then by the person's access (the guard's
 * reach, `canViewBoard()`, `canViewTask()`) **before** it is paged or counted: a task the reader cannot open is in no page,
 * count or bucket; a page may hold fewer rows than `limit` when rows it scanned are not visible. Single objects out of
 * reach answer `404 not_found` (the same as unknown and out-of-context ids). Everything is loaded per request; nothing is
 * kept on the service (worker mode).
 *
 * @internal
 */
final readonly class TaskClientReader
{
    /** Cards per page by default (a kanban column, §4.2). */
    public const COLUMN_PAGE = 30;

    /** Comments per page by default. */
    public const COMMENTS_PAGE = 20;

    /** Changes per poll by default and at most. */
    public const CHANGES_LIMIT = 200;

    /** A `changes` cursor older than this is a reset (seconds). */
    public const CHANGES_MAX_AGE = 86400;

    /** Days of the `upcoming` bucket after today. */
    public const UPCOMING_DAYS = 14;

    /** The buckets of `GET my-tasks`. */
    public const BUCKETS = ['overdue', 'today', 'upcoming', 'no_date', 'review', 'all'];

    public function __construct(
        private TaskApiGuard $guard,
        private TaskApiReadOperations $reads,
        private TaskApiQueryRepository $apiQueries,
        private TaskClientQueries $queries,
        private TaskClientSerializer $serializer,
        private TaskClientContextPolicyInterface $contexts,
        private TaskApiUsersInterface $users,
        private TaskApiWorkspaceInterface $workspace,
        private TaskBoardRepositoryInterface $boards,
        private TaskMemberAssigner $members,
        private TaskTextTokens $text,
        // Since audit ZCS-L2: sealed cursors.
        private TaskClientCursor $cursors,
        private ?TaskTextSearchInterface $search = null,
        private int $searchMaxMatches = 500,
        private ?TaskApiQuestionOperations $questions = null,
        private ?TaskQuestionQuery $questionQuery = null,
        private ?TaskQuestionManager $questionManager = null,
        private bool $timeTracking = false,
        private bool $taskNumbers = false,
        private ?int $subtaskDepth = null,
    ) {
    }

    /**
     * `GET me`.
     *
     * @return array<string, mixed>
     */
    public function me(TaskClientCall $call): array
    {
        $principal = $call->principal;
        $contexts  = array_map(static fn (TaskClientContext $context): array => $context->toArray(), $this->contexts->contextsFor($call->client));
        $current   = ['key' => $call->contextKey, 'label' => ''];
        foreach ($contexts as $context) {
            if ($context['key'] === $call->contextKey) {
                $current = $context;
            }
        }

        return [
            'apiVersion' => 1,
            'user'       => $this->serializer->user($this->profile($principal, $principal->user)),
            'scopes'     => $call->client->scopes,
            'context'    => $current,
            'contexts'   => $contexts,
            'features'   => [
                'realtime'     => false,
                'questions'    => $this->questions instanceof TaskApiQuestionOperations,
                'timeTracking' => $this->timeTracking,
                'taskNumbers'  => $this->taskNumbers,
                'templates'    => false,
                'subtaskDepth' => $this->subtaskDepth,
                'files'        => false,
                'activity'     => false,
            ],
            'limits' => ['pageSize' => 100, 'columnPageSize' => self::COLUMN_PAGE, 'changes' => self::CHANGES_LIMIT, 'myTasksScan' => TaskClientQueries::MY_TASKS_SCAN],
            'enums'  => [
                'priorities'  => array_map(static fn (TaskPriority $priority): string => $priority->value, TaskPriority::cases()),
                'memberRoles' => array_map(static fn (TaskMemberRole $role): string => $role->value, TaskMemberRole::cases()),
                'columnTypes' => array_map(static fn (ColumnType $type): string => $type->value, ColumnType::cases()),
            ],
        ];
    }

    /**
     * `GET boards?cursor=&limit=&q=`: the context's boards the person may view, by name.
     *
     * @return array<string, mixed>
     */
    public function boards(TaskClientCall $call, Request $request): array
    {
        $limit = TaskClientInput::limit($request, 50);
        $q     = $this->query($request);
        $after = $this->cursor($request, 'boards', 2);
        $rows  = [];
        foreach ($this->contextBoards($call) as $board) {
            if ($q !== null && !str_contains(TextFold::fold($board->getName()), TextFold::fold($q))) {
                continue;
            }
            $key = [mb_strtolower($board->getName()), $board->getId()];
            if ($after === null || $key > $after) {
                $rows[] = [$key, $board];
            }
        }
        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $page = array_slice($rows, 0, $limit);

        // Audit ZCS-L2: no count in the list (a count of every open task would include the ones the reader may not see);
        // GET boards/{id} counts the visible ones.
        return [
            'boards'     => array_map(fn (array $row): array => $this->serializer->board($row[1], $call->principal->user, null, $this->group($call, $row[1])), $page),
            'nextCursor' => count($rows) > $limit && $page !== [] ? $this->cursors->encode('boards', $page[array_key_last($page)][0]) : null,
        ];
    }

    /**
     * `GET boards/{id}`: the board, the people the reader may assign there and the tags in use.
     *
     * @return array<string, mixed>
     */
    public function board(TaskClientCall $call, string $boardId): array
    {
        $board = $this->boardOf($call, $boardId);
        // Audit ZCS-L2: the open tasks the reader may open, at most MY_TASKS_SCAN of them read.
        $open    = $this->queries->openTaskIds($board->getId(), TaskClientQueries::MY_TASKS_SCAN + 1);
        $capped  = count($open) > TaskClientQueries::MY_TASKS_SCAN;
        $counts  = ['open' => count($this->visible($call, array_slice($open, 0, TaskClientQueries::MY_TASKS_SCAN), false)), 'capped' => $capped];
        $people  = $this->members->assignableUsers($board) ?? [];
        $profile = $people === [] ? [] : $this->users->profiles(array_slice($people, 0, 100), $call->principal);

        return ['board' => $this->serializer->board($board, $call->principal->user, $counts, $this->group($call, $board)) + [
            'people'    => array_values(array_map(fn (TaskApiUser $user): array => (array) $this->serializer->user($user), $profile)),
            'tags'      => array_slice($this->apiQueries->tagsOf([$board->getId()]), 0, 50),
            'templates' => [],
        ]];
    }

    /**
     * `GET boards/{id}/tasks`: one column's cards in board order, or the list view in a sort, with filters.
     *
     * @return array<string, mixed>
     */
    public function boardTasks(TaskClientCall $call, string $boardId, Request $request): array
    {
        $board  = $this->boardOf($call, $boardId);
        $column = $this->queryString($request, 'column');
        $sort   = $this->queryString($request, 'sort') ?? 'position';
        if (!in_array($sort, TaskClientQueries::SORTS, true) || ($column !== null && $sort !== 'position')) {
            throw TaskClientApiException::invalidField('sort');
        }
        if ($column !== null && !$this->hasColumn($board, $column)) {
            throw TaskClientApiException::invalidField('column');
        }
        $filter = ['column' => $column, 'today' => new DateTimeImmutable('today', new DateTimeZone('UTC'))];
        $parent = $this->queryString($request, 'parent');
        if ($parent !== null) {
            $task = $this->taskOf($call, $parent);
            if ($task->getBoard()->getId() !== $board->getId()) {
                throw TaskClientApiException::invalidField('parent');
            }
            $filter['parent'] = $task->getId();
        }
        $assignee = $this->queryString($request, 'assignee');
        if ($assignee !== null) {
            $assignee === 'me' ? $filter['me'] = $call->principal->user : throw TaskClientApiException::invalidField('assignee');
        }
        $filter['priorities'] = array_map(static fn (string $value): TaskPriority => TaskPriority::tryFrom($value) ?? throw TaskClientApiException::invalidField('priority'), $this->queryList($request, 'priority'));
        $filter['tags']       = $this->queryList($request, 'tag');
        $due                  = $this->queryString($request, 'due');
        if ($due !== null && !in_array($due, ['overdue', 'today', 'week', 'none'], true)) {
            throw TaskClientApiException::invalidField('due');
        }
        $filter['due'] = $due;
        $q             = $this->query($request);
        if ($q !== null) {
            $filter['ids'] = $this->search($board, $q);
        }
        $kind  = 'b' . $board->getId() . '/' . $sort . '/' . ($column ?? '') . '/' . ($parent ?? '');
        $limit = TaskClientInput::limit($request, self::COLUMN_PAGE);
        $after = $this->cursor($request, $kind, $sort === 'position' && $column === null ? 3 : 2);
        $rows  = $this->queries->boardPage($board->getId(), $sort, $filter, $after, $limit);
        $more  = count($rows) > $limit;
        $rows  = array_slice($rows, 0, $limit);
        $ids   = $this->visible($call, array_map(static fn (array $row): string => $row['id'], $rows), isset($filter['me']));

        return ['tasks' => $this->cards($call, $ids), 'nextCursor' => $more && $rows !== [] ? $this->cursors->encode($kind, $rows[array_key_last($rows)]['cursor']) : null];
    }

    /**
     * `GET my-tasks?bucket=&today=&cursor=&limit=`: the caller's open tasks as assignee (the `review` bucket: as reviewer)
     * in the context's boards, by due date, priority and id; the first page also counts the buckets.
     *
     * @return array<string, mixed>
     */
    public function myTasks(TaskClientCall $call, Request $request): array
    {
        $bucket = $this->queryString($request, 'bucket') ?? 'all';
        if (!in_array($bucket, self::BUCKETS, true)) {
            throw TaskClientApiException::invalidField('bucket');
        }
        $today  = $this->today($this->queryString($request, 'today'));
        $limit  = TaskClientInput::limit($request, 50);
        $after  = $this->cursor($request, 'my/' . $bucket . '/' . $today->format('Y-m-d'), 3);
        $me     = SyncUser::id($call->principal->user);
        $boards = array_map(static fn (TaskBoard $board): string => $board->getId(), $this->contextBoards($call));
        $all    = [];
        foreach ($this->apiQueries->load($this->queries->myTaskIds($boards, $call->principal->user)) as $task) {
            $roles = [];
            foreach ($task->getMembers() as $member) {
                // Exact (REQ-SEC-006): a look-alike id in a folding collation is someone else.
                if ($me !== null && SyncUser::id($member->getUser()) === $me) {
                    $roles[] = $member->getMemberRole();
                }
            }
            $column = $task->getColumn();
            if ($roles === [] || !$column instanceof BoardColumn || !in_array(ColumnTypes::of($column, ColumnTypes::ordered($task->getBoard())), [ColumnType::Open, ColumnType::Custom], true) || !$this->guard->canSeeTask($call->principal, $task)) {
                continue;
            }
            $all[] = [$task, $roles];
        }
        $counts  = ['overdue' => 0, 'today' => 0, 'upcoming' => 0, 'review' => 0];
        $matched = [];
        foreach ($all as [$task, $roles]) {
            $buckets = $this->buckets($task, $roles, $today);
            foreach ($buckets as $name) {
                if (isset($counts[$name])) {
                    ++$counts[$name];
                }
            }
            if (in_array($bucket, $buckets, true)) {
                $key = [$task->getDueAt()?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') ?? '9999-12-31 23:59:59', $this->rank($task->getPriority()), $task->getId()];
                if ($after === null || $key > $after) {
                    $matched[] = [$key, $task];
                }
            }
        }
        usort($matched, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $page   = array_slice($matched, 0, $limit);
        $answer = [
            'tasks'      => $this->cards($call, array_map(static fn (array $row): string => $row[1]->getId(), $page)),
            'nextCursor' => count($matched) > $limit && $page !== [] ? $this->cursors->encode('my/' . $bucket . '/' . $today->format('Y-m-d'), $page[array_key_last($page)][0]) : null,
        ];
        if ($after === null) {
            $answer['counts'] = $counts;
        }

        return $answer;
    }

    /**
     * `GET tasks/{id}` (or `?number=&board=`).
     *
     * @return array<string, mixed>
     */
    public function task(TaskClientCall $call, string $taskId): array
    {
        $task             = $this->taskOf($call, $taskId);
        $page             = $this->reads->taskList($call->principal, [$task->getId()]);
        [$open, $waiting] = $this->questionCounts($call, [$task]);

        return ['task' => $this->serializer->detail($page->tasks[0] ?? $task, $page, $call, $open, $waiting, $this->queries->commentCounts([$task->getId()]), $this->apiQueries->dependenciesOf([$task->getId()]))];
    }

    /** The task of a number in a board's sequence (`?number=123&board=<id>`), else `404`. */
    public function taskIdByNumber(TaskClientCall $call, string $number, string $boardId): string
    {
        $board = $this->boardOf($call, $boardId);
        // The controller only calls with `123` or `#123`.
        try {
            return $this->reads->taskByNumber($call->principal, $number, null, $board->getId())->tasks[0]->getId() ?? throw TaskClientApiException::notFound();
        } catch (TaskApiRefusal) {
            throw TaskClientApiException::notFound();
        }
    }

    /**
     * `GET tasks/{id}/subtasks?cursor=`: one level, board order.
     *
     * @return array<string, mixed>
     */
    public function subtasks(TaskClientCall $call, string $taskId, Request $request): array
    {
        $task  = $this->taskOf($call, $taskId);
        $kind  = 's' . $task->getId();
        $limit = TaskClientInput::limit($request, 50);
        $rows  = $this->queries->boardPage($task->getBoard()->getId(), 'position', ['parent' => $task->getId()], $this->cursor($request, $kind, 3), $limit);
        $more  = count($rows) > $limit;
        $rows  = array_slice($rows, 0, $limit);
        $ids   = $this->visible($call, array_map(static fn (array $row): string => $row['id'], $rows), false);

        return ['tasks' => $this->cards($call, $ids), 'nextCursor' => $more && $rows !== [] ? $this->cursors->encode($kind, $rows[array_key_last($rows)]['cursor']) : null];
    }

    /**
     * `GET tasks/{id}/comments?before=&limit=`: newest first.
     *
     * @return array<string, mixed>
     */
    public function comments(TaskClientCall $call, string $taskId, Request $request): array
    {
        $task   = $this->taskOf($call, $taskId);
        $kind   = 'c' . $task->getId();
        $limit  = TaskClientInput::limit($request, self::COMMENTS_PAGE);
        $before = $this->queryString($request, 'before', TaskClientCursor::MAX_LENGTH);
        $after  = $before === null ? null : $this->cursors->decode($before, $kind, 2) ?? throw new TaskClientApiException('invalid_cursor', 400);
        $rows   = $this->apiQueries->comments($task, $after === null ? null : new DateTimeImmutable((string) $after[0]), $after === null ? null : (string) $after[1], $limit + 1);
        $more   = count($rows) > $limit;
        $rows   = array_slice($rows, 0, $limit);
        $people = [];
        foreach ($rows as $comment) {
            if ($comment->getAuthor() !== null) {
                $people[spl_object_id($comment->getAuthor())] = $comment->getAuthor();
            }
        }
        $profiles = $people === [] ? [] : $this->users->profiles(array_values($people), $call->principal);
        $last     = $rows === [] ? null : $rows[array_key_last($rows)];

        return [
            'comments'   => array_map(fn (TaskComment $comment): array => $this->serializer->comment($comment, $comment->getAuthor() === null ? null : $profiles[$this->apiId($comment->getAuthor()) ?? ''] ?? null, $call->principal->user), $rows),
            'nextCursor' => $more && $last instanceof TaskComment ? $this->cursors->encode($kind, [$last->getCreatedAt()->format('Y-m-d H:i:s'), $last->getId()]) : null,
        ];
    }

    /**
     * `GET tasks/{id}/questions?state=`: the Task API's shape, with `context` as tokens/1 and `waitingForMe`.
     *
     * @return array<string, mixed>
     */
    public function questions(TaskClientCall $call, string $taskId, ?string $state): array
    {
        if (!$this->questions instanceof TaskApiQuestionOperations) {
            throw TaskClientApiException::notFound();
        }
        $task = $this->taskOf($call, $taskId);
        if ($state !== null && !in_array($state, ['open', 'answered', 'withdrawn'], true)) {
            throw TaskClientApiException::invalidField('state');
        }
        [, $questions] = $this->questions->taskQuestions($call->principal, $task->getId(), $state);

        return ['questions' => $this->questionRows($call, $questions)];
    }

    /**
     * `GET questions/waiting`: the open questions addressed to the caller on tasks of the context, 100 at most.
     *
     * @return array<string, mixed>
     */
    public function waiting(TaskClientCall $call): array
    {
        if (!$this->questions instanceof TaskApiQuestionOperations) {
            throw TaskClientApiException::notFound();
        }

        return ['questions' => $this->questionRows($call, $this->questions->myQuestions($call->principal, 100))];
    }

    /**
     * `GET people?q=&board=`: the people the caller may assign on the board whose name starts with `q`, 20 at most.
     *
     * @return array<string, mixed>
     */
    public function people(TaskClientCall $call, Request $request): array
    {
        $boardId = $this->queryString($request, 'board');
        if ($boardId === null) {
            throw TaskClientApiException::invalidField('board');
        }
        $board = $this->boardOf($call, $boardId);
        $q     = trim((string) $this->query($request));
        $found = [];
        $space = $this->workspace->spaceOfTenant($call->principal, $board->getTenantKey());
        if ($space instanceof TaskApiSpace) {
            // The host's own team (the board's space); `q` is already at most 128 characters.
            foreach ($this->reads->findMembers($call->principal, $space->teamId, $q) as $member) {
                $user = $this->users->find($member->id);
                if ($user !== null && $this->members->canBeAssigned($board, $user) && !$member->isServiceAccount()) {
                    $found[] = $this->memberOf($member);
                }
            }
        }

        return ['people' => array_slice($found, 0, 20)];
    }

    /**
     * `GET changes?since=&limit=`: the tasks of the context the reader may open changed since the cursor (no tombstones).
     * Without `since`: none, and a cursor from now. `reset: true` when the cursor is older than 24 hours or more than
     * `limit` changed — the client reloads what it shows.
     *
     * @return array<string, mixed>
     */
    public function changes(TaskClientCall $call, Request $request): array
    {
        $limit = TaskClientInput::limit($request, self::CHANGES_LIMIT, self::CHANGES_LIMIT);
        $since = $this->queryString($request, 'since', TaskClientCursor::MAX_LENGTH);
        $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($since === null) {
            return ['changes' => [], 'nextCursor' => $this->cursors->encode('changes/' . $call->contextKey, [$now->format('Y-m-d H:i:s'), '']), 'reset' => true];
        }
        $after = $this->cursors->decode($since, 'changes/' . $call->contextKey, 2) ?? throw new TaskClientApiException('invalid_cursor', 400);
        $at    = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $after[0], new DateTimeZone('UTC'));
        if ($at === false) {
            throw new TaskClientApiException('invalid_cursor', 400);
        }
        $boards = array_map(static fn (TaskBoard $board): string => $board->getId(), $this->contextBoards($call));
        $rows   = $this->queries->changes($boards, [(string) $after[0], (string) $after[1]], $limit);
        $reset  = $now->getTimestamp() - $at->getTimestamp() > self::CHANGES_MAX_AGE || count($rows) > $limit;
        $rows   = array_slice($rows, 0, $limit);
        $shown  = array_flip($this->visible($call, array_map(static fn (array $row): string => $row['id'], $rows), false));
        $last   = $rows === [] ? null : $rows[array_key_last($rows)];

        return [
            'changes'    => array_values(array_map(static fn (array $row): array => ['taskId' => $row['id'], 'boardId' => $row['boardId'], 'revision' => $row['revision']], array_filter($rows, static fn (array $row): bool => isset($shown[$row['id']])))),
            'nextCursor' => $this->cursors->encode('changes/' . $call->contextKey, $last === null ? [(string) $after[0], (string) $after[1]] : [$last['updatedAt'], $last['id']]),
            'reset'      => $reset,
        ];
    }

    /**
     * The boards of the context the person may see.
     *
     * @return list<TaskBoard>
     */
    public function contextBoards(TaskClientCall $call): array
    {
        $where = $call->principal->where;
        // The listener refuses an `everything()` context (audit ZCS-L1).
        $boards = $this->apiQueries->activeBoardsOfTenants($where->tenantKeys() ?? []);
        $seen   = [];
        foreach ($boards as $board) {
            $seen[$board->getId()] = true;
        }
        foreach ($where->boardIds() as $boardId) {
            $board = isset($seen[$boardId]) ? null : $this->boards->findById($boardId);
            if ($board instanceof TaskBoard && $board->getId() === $boardId && !$board->isArchived()) {
                $boards[]       = $board;
                $seen[$boardId] = true;
            }
        }

        return array_values(array_filter($boards, fn (TaskBoard $board): bool => $this->guard->canSeeBoard($call->principal, $board)));
    }

    /** A board of the context the person may see, else `404`. */
    public function boardOf(TaskClientCall $call, string $boardId): TaskBoard
    {
        // REQ-SEC-006: a well-formed id, and exactly this one (a folding collation may answer a look-alike).
        $board = TaskClientInput::isId($boardId) ? $this->guard->board($call->principal, $boardId, TaskApiCapability::ReadOwn) : null;
        if (!$board instanceof TaskBoard || $board->isArchived() || $board->getId() !== $boardId) {
            throw TaskClientApiException::notFound();
        }

        return $board;
    }

    /** A task of the context the person may open, else `404`. */
    public function taskOf(TaskClientCall $call, string $taskId): Task
    {
        // REQ-SEC-006: a well-formed id, and exactly this one (a folding collation may answer a look-alike).
        $task = TaskClientInput::isId($taskId) ? $this->guard->task($call->principal, $taskId, TaskApiCapability::ReadOwn) : null;
        if (!$task instanceof Task || $task->getId() !== $taskId) {
            throw TaskClientApiException::notFound();
        }

        return $task;
    }

    /**
     * Cards of tasks already found visible, in the given order.
     *
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function cards(TaskClientCall $call, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $page             = $this->reads->taskList($call->principal, $ids);
        [$open, $waiting] = $this->questionCounts($call, $page->tasks);
        $comments         = $this->queries->commentCounts($ids);

        return array_map(fn (Task $task): array => $this->serializer->card($task, $page, $open, $waiting, $comments), $page->tasks);
    }

    /**
     * The detail of a task the caller just wrote (its answer).
     *
     * @return array<string, mixed>
     */
    public function written(TaskClientCall $call, Task $task): array
    {
        $page             = $this->reads->taskList($call->principal, [$task->getId()]);
        [$open, $waiting] = $this->questionCounts($call, [$task]);

        return $this->serializer->detail($page->tasks[0] ?? $task, $page, $call, $open, $waiting, $this->queries->commentCounts([$task->getId()]), $this->apiQueries->dependenciesOf([$task->getId()]));
    }

    /**
     * The ids the person may open, in order (`$exactMe`: and assigned to exactly the caller).
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function visible(TaskClientCall $call, array $ids, bool $exactMe): array
    {
        if ($ids === []) {
            return [];
        }
        $me    = SyncUser::id($call->principal->user);
        $shown = [];
        foreach ($this->apiQueries->load($ids) as $task) {
            if (!$this->guard->canSeeTask($call->principal, $task)) {
                continue;
            }
            if ($exactMe) {
                $mine = false;
                foreach ($task->getMembers() as $member) {
                    $mine = $mine || ($member->getMemberRole() === TaskMemberRole::Assignee && $me !== null && SyncUser::id($member->getUser()) === $me);
                }
                if (!$mine) {
                    // Only a look-alike id of a folding collation reaches here (the query asked for the caller's id).
                    continue;
                }
            }
            $shown[] = $task->getId();
        }

        return $shown;
    }

    /**
     * Open questions per task and the tasks with one waiting for the caller.
     *
     * @param list<Task> $tasks
     *
     * @return array{0: array<string, int>, 1: array<string, true>}
     */
    private function questionCounts(TaskClientCall $call, array $tasks): array
    {
        if (!$this->questionQuery instanceof TaskQuestionQuery || $tasks === []) {
            return [[], []];
        }
        $ids     = array_map(static fn (Task $task): string => $task->getId(), $tasks);
        $boards  = array_values(array_unique(array_map(static fn (Task $task): string => $task->getBoard()->getId(), $tasks)));
        $waiting = [];
        foreach ($this->questionQuery->tasksOpenForSubjects($this->subjects($call), $boards) as $task) {
            if (in_array($task->getId(), $ids, true)) {
                $waiting[$task->getId()] = true;
            }
        }

        return [$this->questionQuery->openCounts($ids), $waiting];
    }

    /**
     * @return list<string>
     */
    private function subjects(TaskClientCall $call): array
    {
        return $this->questionManager?->subjectIdsOf($call->principal->user, null) ?? [];
    }

    /**
     * @param list<TaskQuestion> $questions
     *
     * @return list<array<string, mixed>>
     */
    private function questionRows(TaskClientCall $call, array $questions): array
    {
        $subjects = $this->subjects($call);
        $rows     = [];
        foreach ($questions as $question) {
            $row                 = TaskApiQuestionController::question(TaskQuestionSnapshot::of($question));
            $row['context']      = $this->text->of($question->getContext(), $call->principal->user);
            $row['waitingForMe'] = false;
            if ($question->isOpen()) {
                foreach ($question->getRecipients() as $recipient) {
                    $row['waitingForMe'] = $row['waitingForMe'] || in_array($recipient->getSubjectId(), $subjects, true);
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Matching ids of the board (the Task API's search rules and `search.max_matches`).
     *
     * @return list<string>
     */
    private function search(TaskBoard $board, string $q): array
    {
        if (!$this->search instanceof TaskTextSearchInterface) {
            // The container always wires one (search.provider, default DoctrineTaskTextSearch).
            throw TaskClientApiException::invalidField('q'); // @codeCoverageIgnore
        }
        $matches = $this->search->matchingTaskIds(new TaskTextQuery($q, [$board->getId()], $this->searchMaxMatches));
        if (count($matches) > $this->searchMaxMatches) {
            throw new TaskClientApiException('search_too_broad', 400);
        }

        return $matches;
    }

    /**
     * The group a board is listed under: its folder, else the host's space of its tenant.
     *
     * @return array{type: string, id: string, label: string}|null
     */
    private function group(TaskClientCall $call, TaskBoard $board): ?array
    {
        $folder = $board->getFolder();
        if ($folder instanceof TaskBoardFolder) {
            return ['type' => 'folder', 'id' => $folder->getId(), 'label' => $folder->getName()];
        }
        $space = $this->workspace->spaceOfTenant($call->principal, $board->getTenantKey());

        return $space instanceof TaskApiSpace ? ['type' => 'space', 'id' => $space->id, 'label' => $space->name] : null;
    }

    /**
     * The buckets of a task for the caller.
     *
     * @param list<TaskMemberRole> $roles
     *
     * @return list<string>
     */
    private function buckets(Task $task, array $roles, DateTimeImmutable $today): array
    {
        $buckets = [];
        if (in_array(TaskMemberRole::Assignee, $roles, true)) {
            $buckets[] = 'all';
            $due       = $task->getDueAt()?->setTimezone(new DateTimeZone('UTC'));
            $buckets[] = match (true) {
                !$due instanceof DateTimeImmutable                               => 'no_date',
                $due < $today                                                    => 'overdue',
                $due < $today->modify('+1 day')                                  => 'today',
                $due < $today->modify('+' . (self::UPCOMING_DAYS + 1) . ' days') => 'upcoming',
                default                                                          => 'later',
            };
        }
        if (in_array(TaskMemberRole::Reviewer, $roles, true)) {
            $buckets[] = 'review';
        }

        return $buckets;
    }

    /**
     * The client's local date (`YYYY-MM-DD`), within a day of the server's UTC date; the server's when absent.
     *
     * @throws TaskClientApiException
     */
    private function today(?string $today): DateTimeImmutable
    {
        $utc = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        if ($today === null) {
            return $utc;
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $today) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $today, new DateTimeZone('UTC')) : false;
        if ($date === false || $date->format('Y-m-d') !== $today || $date < $utc->modify('-1 day') || $date > $utc->modify('+1 day')) {
            throw new TaskClientApiException('invalid_date', 400);
        }

        return $date;
    }

    private function rank(TaskPriority $priority): int
    {
        return match ($priority) {
            TaskPriority::Urgent => 0,
            TaskPriority::High   => 1,
            TaskPriority::Normal => 2,
            TaskPriority::Low    => 3,
        };
    }

    private function hasColumn(TaskBoard $board, string $columnId): bool
    {
        foreach ($board->getColumns() as $column) {
            if ($column->getId() === $columnId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id: string, name: string, initials: string, avatarUrl: string|null}
     */
    private function memberOf(TaskApiMember $member): array
    {
        return $this->serializer->user(new TaskApiUser($member->id, $member->name, null, $member->color, $member->profilePicture)) ?? ['id' => $member->id, 'name' => $member->name, 'initials' => '', 'avatarUrl' => null];
    }

    private function profile(TaskApiPrincipal $principal, object $user): ?TaskApiUser
    {
        $id = $this->apiId($user);

        return $id === null ? null : ($this->users->profiles([$user], $principal)[$id] ?? null);
    }

    private function apiId(object $user): ?string
    {
        try {
            return $this->users->apiId($user);
        } catch (TaskApiRefusal) {
            return null;
        }
    }

    /**
     * A cursor of this list (or none); a cursor of another list or shape is `400 invalid_cursor`.
     *
     * @return list<int|string>|null
     */
    private function cursor(Request $request, string $kind, int $count): ?array
    {
        $cursor = $this->queryString($request, 'cursor', TaskClientCursor::MAX_LENGTH);

        return $cursor === null ? null : $this->cursors->decode($cursor, $kind, $count) ?? throw new TaskClientApiException('invalid_cursor', 400);
    }

    /** `q`: the words searched (at most 128 characters), or null. */
    private function query(Request $request): ?string
    {
        $q = $this->queryString($request, 'q');
        if ($q === null || trim($q) === '') {
            return null;
        }
        if (mb_strlen($q) > 128) {
            throw TaskClientApiException::invalidField('q');
        }

        return $q;
    }

    /**
     * @param int $max longest value (characters); cursors take TaskClientCursor::MAX_LENGTH (audit ZCY-L1)
     */
    private function queryString(Request $request, string $name, int $max = 256): ?string
    {
        $value = $request->query->all()[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > $max) {
            throw TaskClientApiException::invalidField($name);
        }

        return $value;
    }

    /**
     * A repeatable query parameter (`?tag=a&tag=b`, or `tag[]=`), read from the raw query string, at most 20 values.
     *
     * @return list<string>
     */
    private function queryList(Request $request, string $name): array
    {
        $values = [];
        foreach (explode('&', (string) $request->server->get('QUERY_STRING', '')) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key           = rawurldecode(str_replace('+', ' ', $key));
            if ($key === $name || $key === $name . '[]') {
                $values[] = rawurldecode(str_replace('+', ' ', $value));
            }
        }
        if (count($values) > 20) {
            throw TaskClientApiException::invalidField($name);
        }
        foreach ($values as $item) {
            if ($item === '' || mb_strlen($item) > 64) {
                throw TaskClientApiException::invalidField($name);
            }
        }

        return $values;
    }
}
