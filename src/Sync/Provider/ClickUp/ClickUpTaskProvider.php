<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider\ClickUp;

use DateTimeImmutable;
use Normalizer;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpTaskMapper;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Content\RemoteContentSanitizer;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\Http\ProviderHttpClient;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerPage;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\FieldCoverageProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\PeopleProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCapabilities;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklist;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklistItem;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocation;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocationKind;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonPage;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusTime;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskDetails;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskPage;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTimeEntry;
use Nowo\TaskBoardBundle\Sync\Provider\ReplyBudget;
use Nowo\TaskBoardBundle\Sync\Provider\SourceProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\StatusHistoryProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskDetailProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ThreadedCommentProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccessMode;
use Nowo\TaskBoardBundle\Sync\Provider\TimeEntryProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TimeEntryQuery;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_push;
use function array_reverse;
use function array_slice;
use function array_unique;
use function array_values;
use function class_exists;
use function count;
use function ctype_digit;
use function hash;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_encode;
use function max;
use function mb_scrub;
use function mb_strcut;
use function mb_strtolower;
use function parse_url;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use function usort;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 1.23: ClickUp API v2. The base URI is a constant (never configurable), the token goes only to it, in the
 * `Authorization` header (personal tokens); paging, rate limits, timeouts and size caps are those of
 * {@see ProviderHttpClient}. Tree ids carry their level: "w:<workspace>", "s:<space>", "f:<folder>"; a list is its
 * plain ClickUp id.
 */
final readonly class ClickUpTaskProvider implements TaskProviderInterface, ThreadedCommentProviderInterface, PeopleProviderInterface, TimeEntryProviderInterface, SourceProviderInterface, TaskDetailProviderInterface, StatusHistoryProviderInterface, FieldCoverageProviderInterface
{
    public const BASE_URI = 'https://api.clickup.com/api/v2/';

    public const ID = 'clickup';

    /** Tasks per page of GET list/{id}/task. */
    public const PAGE_SIZE = 100;

    /** Comments per page of GET task/{id}/comment. */
    public const COMMENT_PAGE_SIZE = 25;

    /** Unconfirmed against the live API (owner decision 2026-11-04); validated by Beacon after deploy. */
    public const DEFAULT_ATTACHMENT_HOSTS = ['*.clickup-attachments.com', 'attachments.clickup.com', 'attachments2.clickup.com'];

    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    /** The most pages the id scan of the deletions phase reads (100 000 tasks). */
    private const MAX_ID_PAGES = 1000;

    /** Since 1.25: the most calls one list search spends (Beacon's interim finder uses the same budget). */
    public const SEARCH_CALL_BUDGET = 40;

    /** Since 1.25: seconds a list catalog stays in the lookup cache. */
    public const SEARCH_CACHE_TTL = 300;

    /** Since 1.25: ClickUp's numeric workspace roles. */
    private const ROLES = [1 => RemoteRole::Owner, 2 => RemoteRole::Admin, 3 => RemoteRole::Member, 4 => RemoteRole::Guest];

    /**
     * Since 1.27.0 (TB-SYNC-3): every key of a ClickUp task answer and what the bundle does with it (the completeness
     * report): `imported`, or why not.
     */
    private const COVERAGE = [
        'id'                     => 'imported', 'custom_id' => 'imported', 'custom_item_id' => 'not_supported', 'name' => 'imported',
        'text_content'           => 'imported', 'description' => 'imported', 'markdown_description' => 'imported',
        'status'                 => 'imported', 'orderindex' => 'not_needed', 'date_created' => 'imported', 'date_updated' => 'imported',
        'date_closed'            => 'imported', 'date_done' => 'imported', 'archived' => 'imported', 'creator' => 'imported',
        'assignees'              => 'imported', 'group_assignees' => 'not_supported', 'watchers' => 'imported',
        'checklists'             => 'imported', 'tags' => 'imported', 'parent' => 'imported', 'top_level_parent' => 'not_needed',
        'priority'               => 'imported', 'due_date' => 'imported', 'start_date' => 'imported', 'due_date_time' => 'imported',
        'start_date_time'        => 'imported', 'points' => 'imported', 'time_estimate' => 'imported',
        'time_estimates_by_user' => 'imported', 'time_spent' => 'imported', 'custom_fields' => 'imported',
        'dependencies'           => 'imported', 'linked_tasks' => 'imported', 'locations' => 'not_supported',
        'team_id'                => 'internal', 'url' => 'imported', 'sharing' => 'internal', 'permission_level' => 'internal',
        'list'                   => 'imported', 'project' => 'not_needed', 'folder' => 'imported', 'space' => 'not_needed',
        'attachments'            => 'imported', 'subtasks' => 'imported', 'recurrence' => 'imported',
    ];

    /** Since 1.27.0 (audit GW-L7): 2000-01-01 and 2100-01-01 UTC. */
    private const MIN_DATE = 946_684_800;

    private const MAX_DATE = 4_102_444_800;

    /** Since 1.25: the most checklist items one task gives (all checklists together). */
    private const MAX_CHECKLIST_ITEMS = 1000;

    /** Since 1.25: the most lists of a folder whose statuses are read one by one (folder status union). */
    private const MAX_FOLDER_LISTS = 50;

    /** Since 1.25: a time entry id (ClickUp: long numeric strings). */
    private const ENTRY_ID_PATTERN = '/^[A-Za-z0-9._:#-]{1,128}$/D';

    /** Since 1.25: controls and bidi overrides removed from a time-entry description (line feeds are kept). */
    private const DESCRIPTION_STRIP = '/[\x00-\x08\x0B-\x1F\x7F\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * @param list<string> $attachmentHosts
     */
    public function __construct(
        private ProviderHttpClient $http,
        private RemoteContentSanitizer $sanitizer = new RemoteContentSanitizer(),
        private ClickUpTaskMapper $mapper = new ClickUpTaskMapper(),
        private array $attachmentHosts = self::DEFAULT_ATTACHMENT_HOSTS,
        private int $maxComments = 500,
        private int $maxDescriptionLength = 100_000,
        // Since 1.23: unexpected answer shapes are logged at debug level (keys only).
        private LoggerInterface $logger = new NullLogger(),
        // Since 1.25: list searches are cached per connection reference (never per token) when a pool is given.
        private ?CacheItemPoolInterface $lookupCache = null,
        // Since 1.27.0 (TB-SYNC-3): typed custom fields.
        private ClickUpCustomFieldParser $fieldParser = new ClickUpCustomFieldParser(),
        // Since 1.27.0 (TB-SYNC-3): archived tasks are read too (a second pass with archived=true) and imported archived.
        private bool $includeArchived = false,
        /**
         * Since 1.27.0 (audit GW-M3): `sync.custom_fields.exclude_types`, also left out of the "Fields" document.
         *
         * @var list<string>
         */
        private array $excludedFieldTypes = [],
        // Since 1.28.0 (TB-SYNC-4): lists, nesting, code blocks and mentions of a comment.
        private ClickUpCommentRenderer $commentRenderer = new ClickUpCommentRenderer(),
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'task_board.sync.provider.clickup';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            comments: true,
            attachments: true,
            dependencies: true,
            customFields: true,
            subtasks: true,
            incremental: true,
            attachmentHosts: $this->attachmentHosts,
            people: true,
            timeEntries: true,
            watchers: true,
            checklists: true,
        );
    }

    public function importScope(ProviderCredential $credential): string
    {
        return self::ID;
    }

    public function listContainers(ProviderCredential $credential, ?string $parentId = null, ?string $cursor = null): ContainerPage
    {
        if ($parentId === null) {
            $items = [];
            foreach ($this->items($this->get($credential, 'team'), 'teams') as $team) {
                $items[] = new RemoteContainer('w:' . $this->itemId($team), $this->name($team), ContainerType::Workspace, null, false, true);
            }

            return new ContainerPage($items);
        }
        if (preg_match('/^([wsf]):(.+)$/D', $parentId, $match) !== 1) {
            throw new ProviderException(ProviderErrorCode::NotFound);
        }
        $id = $this->checkId($match[2]);

        return new ContainerPage(match ($match[1]) {
            'w' => $this->spaces($credential, $id, $parentId),
            's' => [...$this->folders($credential, $id, $parentId), ...$this->lists($credential, sprintf('space/%s/list', $id), $parentId)],
            'f' => $this->lists($credential, sprintf('folder/%s/list', $id), $parentId),
        });
    }

    public function findContainer(ProviderCredential $credential, string $containerId): ?RemoteContainer
    {
        if (preg_match(self::ID_PATTERN, $containerId) !== 1) {
            return null;
        }
        try {
            $list = $this->get($credential, sprintf('list/%s', $containerId));
        } catch (ProviderException $exception) {
            if ($exception->errorCode === ProviderErrorCode::NotFound) {
                return null;
            }

            throw $exception;
        }
        $path   = [];
        $space  = is_array($list['space'] ?? null) ? $list['space'] : [];
        $folder = is_array($list['folder'] ?? null) ? $list['folder'] : [];
        if ($this->name($space) !== '') {
            $path[] = $this->name($space);
        }
        if ($this->name($folder) !== '' && ($folder['hidden'] ?? false) !== true) {
            $path[] = $this->name($folder);
        }

        return new RemoteContainer($this->itemId($list), $this->name($list), ContainerType::List, null, true, false, $this->count($list['task_count'] ?? null), $path);
    }

    public function fetchTasks(ProviderCredential $credential, string $listId, ?DateTimeImmutable $updatedSince, ?string $cursor): RemoteTaskPage
    {
        [$archived, $page] = $this->pass($cursor);
        $query             = [
            'page'                         => $page,
            'subtasks'                     => 'true',
            'include_closed'               => 'true',
            'include_markdown_description' => 'true',
            'order_by'                     => 'updated',
            // Unconfirmed against the live API (owner decision 2026-11-04); validated by Beacon after deploy.
            // reverse=true is meant to list the oldest update first, so a task updated during the scan moves to a later
            // page instead of shifting the pages not read yet. The run does not depend on it: the watermark is the
            // newest date of all pages, and the overlap window of the next run re-reads the edge.
            'reverse' => 'true',
        ];
        if ($updatedSince instanceof DateTimeImmutable) {
            $query['date_updated_gt'] = (string) ($updatedSince->getTimestamp() * 1000);
        }
        if ($archived) {
            $query['archived'] = 'true';
        }
        $data  = $this->get($credential, sprintf('list/%s/task', $this->checkId($listId)), $query);
        $tasks = [];
        foreach ($this->items($data, 'tasks') as $row) {
            $task = $this->toRemoteTask($row);
            if ($task instanceof RemoteTask) {
                $tasks[] = $task;
            }
        }

        return $this->taskPage($tasks, $archived, $page, $this->lastPage($data, count($this->items($data, 'tasks'))));
    }

    public function fetchComments(ProviderCredential $credential, string $taskId): iterable
    {
        return $this->fetchCommentsWithin($credential, $taskId, new ReplyBudget($this->maxComments));
    }

    public function fetchCommentsWithin(ProviderCredential $credential, string $taskId, ReplyBudget $budget): iterable
    {
        $path     = sprintf('task/%s/comment', $this->checkId($taskId));
        $comments = [];
        $replies  = [];
        $query    = [];
        // Audit EG-L2: raw rows count, at most ceil(max / 25) + 1 pages, and a page that does not move stops.
        $pageCap = intdiv($this->maxComments + self::COMMENT_PAGE_SIZE - 1, self::COMMENT_PAGE_SIZE) + 1;
        for ($pages = 1, $rowsRead = 0; count($comments) < $this->maxComments; ++$pages) {
            $rows = $this->items($this->get($credential, $path, $query), 'comments');
            $rowsRead += count($rows);
            foreach ($rows as $row) {
                $comment = $this->toRemoteComment($row);
                if ($comment instanceof RemoteComment && count($comments) < $this->maxComments) {
                    $comments[] = $comment;
                    // Since 1.27.0 (TB-SYNC-3): threads; the replies are read after the top-level comments.
                    if (is_numeric($row['reply_count'] ?? null) && (int) $row['reply_count'] > 0) {
                        $replies[$comment->id] = true;
                    }
                }
            }
            $oldest = $rows === [] ? [] : $rows[count($rows) - 1];
            // Older comments: start = the oldest date seen, start_id = its id (the documented paging). Unconfirmed against the live API (owner decision 2026-11-04); validated by Beacon after deploy.
            $next = ['start' => $this->stringOf($oldest['date'] ?? null), 'start_id' => $this->itemId($oldest)];
            if (count($rows) < self::COMMENT_PAGE_SIZE || $next['start_id'] === '' || $next === $query || $pages >= $pageCap || $rowsRead >= $this->maxComments) {
                break;
            }
            $query = $next;
        }

        // The API answers newest first; each thread follows its comment, oldest reply first.
        $threaded = [];
        $left     = $this->maxComments - count($comments);
        foreach (array_reverse($comments) as $comment) {
            $threaded[] = $comment;
            if ($left <= 0 || !isset($replies[$comment->id]) || !$budget->take()) {
                continue;
            }
            // Audit GW-L6: a request that brings no reply also counts against the task's cap.
            // Audit GW-I9: a comment deleted meanwhile (404) has no thread; it does not fail the run.
            // Unconfirmed against the live API (GET comment/{id}/reply); validated by Beacon after deploy.
            $thread = [];
            $rows   = $this->items($this->getOrNull($credential, sprintf('comment/%s/reply', $this->checkId($comment->id))) ?? [], 'comments');
            if ($rows === []) {
                --$left;
            }
            foreach ($rows as $row) {
                $reply = $this->toRemoteComment($row, $comment->id);
                if ($reply instanceof RemoteComment && $left > 0) {
                    $thread[] = $reply;
                    --$left;
                }
            }
            usort($thread, static fn (RemoteComment $a, RemoteComment $b): int => $a->createdAt <=> $b->createdAt);
            array_push($threaded, ...$thread);
        }

        return $threaded;
    }

    public function existingTaskIds(ProviderCredential $credential, string $listId): iterable
    {
        $path = sprintf('list/%s/task', $this->checkId($listId));
        for ($page = 0;; ++$page) {
            if ($page >= self::MAX_ID_PAGES) {
                // A runaway paging loop: a partial answer must never look complete.
                throw new ProviderException(ProviderErrorCode::ResponseTooLarge);
            }
            $data = $this->get($credential, $path, ['page' => $page, 'subtasks' => 'true', 'include_closed' => 'true']);
            $rows = $this->items($data, 'tasks');
            foreach ($rows as $row) {
                $id = $this->itemId($row);
                if ($id !== '' && ($row['archived'] ?? false) !== true) {
                    yield $id;
                }
            }
            if ($this->lastPage($data, count($rows))) {
                return;
            }
        }
    }

    /**
     * Since 1.25 (E3): the workspace of a list, folder or space — the space is read from the list or folder, then the
     * workspaces' spaces are walked until it is found (at most one call per workspace).
     */
    public function workspaceOf(ProviderCredential $credential, string $containerId): ?string
    {
        $location = RemoteLocation::fromContainerId($containerId);
        if (!$location instanceof RemoteLocation) {
            return null;
        }
        $spaceId = $location->id;
        if ($location->kind !== RemoteLocationKind::Space) {
            $data = $this->getOrNull($credential, sprintf('%s/%s', $location->kind === RemoteLocationKind::List ? 'list' : 'folder', $location->id));
            if ($data === null) {
                return null;
            }
            $spaceId = $this->itemId(is_array($data['space'] ?? null) ? $data['space'] : []);
            if ($spaceId === '') {
                $this->unexpected('space', $data);

                return null;
            }
        }
        foreach ($this->items($this->get($credential, 'team'), 'teams') as $team) {
            $teamId = $this->itemId($team);
            if ($teamId === '') {
                continue;
            }
            foreach ($this->items($this->get($credential, sprintf('team/%s/space', $teamId), ['archived' => 'false']), 'spaces') as $space) {
                if ($this->itemId($space) === $spaceId) {
                    return $teamId;
                }
            }
        }

        return null;
    }

    /**
     * Since 1.25 (E1): the members of a workspace (one answer, no paging). Roles 1–4 are owner, admin, member and
     * guest; the role is read on the user, else on the member. A workspace the token cannot see is an empty page.
     */
    public function fetchPeople(ProviderCredential $credential, string $workspaceId, ?string $cursor = null): RemotePersonPage
    {
        $people = [];
        foreach ($this->members($credential, $workspaceId) as [$user, $role]) {
            $ref = $this->mapper->personRef($user);
            if (!$ref instanceof RemotePersonRef) {
                continue;
            }
            $avatar   = $this->stringOf($user['profilePicture'] ?? null);
            $people[] = new RemotePerson($ref->providerUserId, $ref->name, $ref->email, $role, str_starts_with($avatar, 'https://') ? $avatar : null);
        }

        return new RemotePersonPage($people);
    }

    /**
     * Since 1.25 (E2 + E1): the token user and its role. Owners and admins read everyone's time; anyone else (or a
     * token user missing from the workspace) only their own.
     */
    public function timeAccess(ProviderCredential $credential, string $workspaceId): TimeAccess
    {
        $me     = $this->get($credential, 'user');
        $userId = $this->itemId(is_array($me['user'] ?? null) ? $me['user'] : []);
        if ($userId === '') {
            $this->unexpected('user', $me);

            return new TimeAccess(TimeAccessMode::Member);
        }
        foreach ($this->members($credential, $workspaceId) as [$user, $role]) {
            if ($this->itemId($user) === $userId) {
                return new TimeAccess($role?->isAdmin() === true ? TimeAccessMode::Admin : TimeAccessMode::Member, $userId);
            }
        }

        return new TimeAccess(TimeAccessMode::Member, $userId);
    }

    /**
     * Since 1.25 (E7): the entries of one window and location. `assignee` is sent only with the query's ids (the
     * directory's people; never user input); without it ClickUp answers the token user's own entries.
     */
    public function fetchTimeEntries(ProviderCredential $credential, TimeEntryQuery $query): iterable
    {
        $params = [
            'start_date'             => (string) ($query->from->getTimestamp() * 1000),
            'end_date'               => (string) ($query->to->getTimestamp() * 1000),
            'include_task_tags'      => 'false',
            'include_location_names' => 'false',
        ];
        $params[$query->location->kind->value . '_id'] = $this->checkId($query->location->id);
        $assignees                                     = array_values(array_filter($query->assigneeIds ?? [], static fn (string $id): bool => preg_match(self::ID_PATTERN, $id) === 1));
        if ($assignees !== []) {
            $params['assignee'] = implode(',', $assignees);
        }
        $entries = [];
        foreach ($this->items($this->get($credential, sprintf('team/%s/time_entries', $this->checkId($query->workspaceId)), $params), 'data') as $row) {
            $task  = is_array($row['task'] ?? null) ? $this->itemId($row['task']) : '';
            $entry = $this->timeEntry($row, $this->mapper->personRef($row['user'] ?? null), $task !== '' ? $task : null);
            if ($entry instanceof RemoteTimeEntry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Since 1.25 (E5): one task with its watchers and checklists, for rows of the task list that leave them out.
     */
    public function fetchTaskDetail(ProviderCredential $credential, string $taskId): ?RemoteTask
    {
        $data = $this->getOrNull($credential, sprintf('task/%s', $this->checkId($taskId)), ['include_markdown_description' => 'true']);

        return $data === null ? null : $this->toRemoteTask($data);
    }

    /**
     * Since 1.25 (E8): the legacy per-task intervals of every user; null when ClickUp no longer serves them (404 / 410).
     */
    public function fetchTaskTimeIntervals(ProviderCredential $credential, string $taskId): ?iterable
    {
        $data = $this->getOrNull($credential, sprintf('task/%s/time', $this->checkId($taskId)));
        if ($data === null) {
            return null;
        }
        $entries = [];
        foreach ($this->items($data, 'data') as $row) {
            $person = $this->mapper->personRef($row['user'] ?? null);
            foreach ($this->items($row, 'intervals') as $interval) {
                $entry = $this->timeEntry(['id' => $interval['id'] ?? null, 'start' => $interval['start'] ?? null, 'end' => $interval['end'] ?? null, 'duration' => $interval['time'] ?? null], $person, $taskId);
                if ($entry instanceof RemoteTimeEntry) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /** Since 1.25: a list ("<id>"), folder ("f:<id>") or space ("s:<id>") with its breadcrumb; null when unknown. */
    public function findSource(ProviderCredential $credential, string $containerId): ?RemoteSource
    {
        $location = RemoteLocation::fromContainerId($containerId);
        if (!$location instanceof RemoteLocation) {
            return null;
        }
        if ($location->kind === RemoteLocationKind::List) {
            $list = $this->findContainer($credential, $location->id);

            return $list instanceof RemoteContainer ? new RemoteSource($location, $list->name, null, $list->path, 1, $list->taskCount) : null;
        }
        $data = $this->getOrNull($credential, sprintf('%s/%s', $location->kind->value, $location->id));
        if ($data === null) {
            return null;
        }
        $space = is_array($data['space'] ?? null) ? $this->name($data['space']) : '';

        return new RemoteSource(
            location: $location,
            name: $this->name($data),
            path: $location->kind === RemoteLocationKind::Folder && $space !== '' ? [$space] : [],
            listCount: is_array($data['lists'] ?? null) ? count($data['lists']) : null,
            openTaskCount: $this->count($data['task_count'] ?? null),
        );
    }

    /**
     * Since 1.25: tasks of a folder or space through the filtered workspace endpoint (`space_ids[]` / `project_ids[]`
     * — ClickUp calls folders "projects" there — / `list_ids[]`), same paging and order as a list. A list source keeps
     * the 1.23 call.
     */
    public function fetchSourceTasks(ProviderCredential $credential, RemoteSource $source, ?DateTimeImmutable $updatedSince, ?string $cursor): RemoteTaskPage
    {
        if ($source->location->kind === RemoteLocationKind::List) {
            return $this->fetchTasks($credential, $source->location->id, $updatedSince, $cursor);
        }
        [$archived, $page] = $this->pass($cursor);
        $query             = $this->sourceFilter($source) + [
            'page'                         => $page,
            'subtasks'                     => 'true',
            'include_closed'               => 'true',
            'include_markdown_description' => 'true',
            'order_by'                     => 'updated',
            'reverse'                      => 'true',
        ];
        if ($updatedSince instanceof DateTimeImmutable) {
            $query['date_updated_gt'] = (string) ($updatedSince->getTimestamp() * 1000);
        }
        if ($archived) {
            $query['archived'] = 'true';
        }
        $data  = $this->get($credential, sprintf('team/%s/task', $this->sourceWorkspace($credential, $source)), $query);
        $rows  = $this->items($data, 'tasks');
        $tasks = [];
        foreach ($rows as $row) {
            $task = $this->toRemoteTask($row);
            if ($task instanceof RemoteTask) {
                $tasks[] = $task;
            }
        }

        return $this->taskPage($tasks, $archived, $page, $this->lastPage($data, count($rows)));
    }

    /** Since 1.25: ids of every task still in a folder or space (a list source keeps the 1.23 scan). */
    public function existingSourceTaskIds(ProviderCredential $credential, RemoteSource $source): iterable
    {
        if ($source->location->kind === RemoteLocationKind::List) {
            yield from $this->existingTaskIds($credential, $source->location->id);

            return;
        }
        $path   = sprintf('team/%s/task', $this->sourceWorkspace($credential, $source));
        $filter = $this->sourceFilter($source);
        for ($page = 0;; ++$page) {
            if ($page >= self::MAX_ID_PAGES) {
                throw new ProviderException(ProviderErrorCode::ResponseTooLarge);
            }
            $data = $this->get($credential, $path, $filter + ['page' => $page, 'subtasks' => 'true', 'include_closed' => 'true']);
            $rows = $this->items($data, 'tasks');
            foreach ($rows as $row) {
                $id = $this->itemId($row);
                if ($id !== '' && ($row['archived'] ?? false) !== true) {
                    yield $id;
                }
            }
            if ($this->lastPage($data, count($rows))) {
                return;
            }
        }
    }

    /**
     * Since 1.25: the statuses of a source in ClickUp order — `GET list/{id}` or `GET space/{id}`; a folder is the
     * union of its lists' statuses (deduplicated by name, first seen wins).
     */
    public function listStatuses(ProviderCredential $credential, RemoteSource $source): array
    {
        $id = $this->checkId($source->location->id);
        if ($source->location->kind !== RemoteLocationKind::Folder) {
            return $this->statuses($this->get($credential, sprintf('%s/%s', $source->location->kind->value, $id)));
        }
        $folder = $this->get($credential, sprintf('folder/%s', $id));
        if (is_array($folder['statuses'] ?? null)) {
            return $this->statuses($folder);
        }
        $union = [];
        foreach (array_slice($this->items($folder, 'lists'), 0, self::MAX_FOLDER_LISTS) as $list) {
            $listId = $this->itemId($list);
            $data   = is_array($list['statuses'] ?? null) || $listId === '' ? $list : $this->get($credential, sprintf('list/%s', $listId));
            foreach ($this->statuses($data) as $status) {
                $union[mb_strtolower($status->name)] ??= new RemoteStatus($status->name, $status->type, $status->color, count($union), $status->openCount);
            }
        }

        return array_values($union);
    }

    /**
     * Since 1.25: the list id of a pasted ClickUp address (`https://app.clickup.com/` only, no credentials or port):
     * `/<team>/v/li/<id>`, `/v/li/<id>` or a list view `/<team>/v/<view>/6-<id>-<n>`. No network call.
     */
    public function resolveListFromUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'app.clickup.com' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $path = $parts['path'] ?? '';
        if (preg_match('#^/(?:\d{1,20}/)?v/li/([A-Za-z0-9_]{1,64})/?$#D', $path, $match) === 1 || preg_match('#^/\d{1,20}/v/[a-z]{1,8}/6-([A-Za-z0-9_]{1,64})-[A-Za-z0-9_-]{1,64}/?$#D', $path, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * Since 1.25: lists whose name contains the query (case and accents ignored), walking workspaces → spaces →
     * folders → lists within {@see self::SEARCH_CALL_BUDGET} calls. The catalog is cached for
     * {@see self::SEARCH_CACHE_TTL} seconds under a hash of the connection reference (never the token).
     */
    public function searchLists(ProviderCredential $credential, string $query, int $limit = 20): array
    {
        $needle = $this->fold($query);
        $found  = [];
        foreach ($this->catalog($credential) as $list) {
            if ($needle === '' || str_contains($this->fold($list->name), $needle)) {
                $found[] = $list;
                if (count($found) >= max(1, $limit)) {
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @param array<mixed> $row
     */
    private function toRemoteTask(array $row): ?RemoteTask
    {
        $markdown    = $this->stringOf($row['markdown_description'] ?? null);
        $description = $markdown !== ''
            ? $this->sanitizer->markdown($markdown, $this->maxDescriptionLength)
            : $this->sanitizer->markdown($this->stringOf($row['text_content'] ?? $row['description'] ?? null), $this->maxDescriptionLength);
        // Titles are one clean line (no controls, no bidi overrides).
        $row['name'] = $this->sanitizer->line($this->stringOf($row['name'] ?? null), 255);
        $task        = $this->mapper->map($row, $description === '' ? null : $description);
        if (!$task instanceof ImportedTaskDto || $task->externalId === null || preg_match(self::ID_PATTERN, $task->externalId) !== 1) {
            return null;
        }

        return new RemoteTask(
            task: $task,
            remoteUpdatedAt: $this->date($row['date_updated'] ?? null) ?? $this->date($row['date_created'] ?? null) ?? new DateTimeImmutable('@0'),
            archived: ($row['archived'] ?? false) === true,
            attachments: $this->attachments($row),
            inlineImageUrls: $this->imageUrls($description),
            customFieldsText: $this->customFields($row),
            watchers: $this->mapper->personRefs($row['watchers'] ?? null),
            watchersKnown: is_array($row['watchers'] ?? null),
            checklists: $this->checklists($row['checklists'] ?? null),
            checklistsKnown: is_array($row['checklists'] ?? null),
            timeSpentSeconds: is_numeric($row['time_spent'] ?? null) && (int) $row['time_spent'] >= 0 ? intdiv((int) $row['time_spent'], 1000) : null,
            status: is_array($row['status'] ?? null) ? $this->status($row['status'], 0) : null,
            listId: is_array($row['list'] ?? null) && ($listId = $this->itemId($row['list'])) !== '' ? $listId : null,
            listName: is_array($row['list'] ?? null) && ($listName = $this->name($row['list'])) !== '' ? $listName : null,
            customFields: $this->fieldParser->parse($row['custom_fields'] ?? null),
            details: $this->details($row),
            presentKeys: $this->presentKeys($row),
            attachmentsKnown: array_key_exists('attachments', $row),
        );
    }

    /**
     * @param array<mixed> $row
     */
    private function toRemoteComment(array $row, ?string $parentId = null): ?RemoteComment
    {
        $id = $this->itemId($row);
        if ($id === '') {
            return null;
        }
        $segments = is_array($row['comment'] ?? null) ? $row['comment'] : [];
        $body     = $segments !== [] ? $this->commentRenderer->render($segments) : '';
        // Since 1.28.0: segments that gave no text (no file among them either) do not lose the comment's plain text.
        if (trim($body) === '' && $this->commentAttachments($segments) === []) {
            $body = $this->stringOf($row['comment_text'] ?? null);
        }
        $body = $this->sanitizer->markdown($body, 5000);
        $user = is_array($row['user'] ?? null) ? $row['user'] : [];
        // Since 1.27.0: an assigned comment (action item) and its resolved state; unconfirmed field names
        // (`assignee`, `resolved`, `date_updated`), validated by Beacon after deploy.
        $assignee = $this->mapper->personRef($row['assignee'] ?? null);

        return new RemoteComment(
            id: $id,
            body: $body,
            authorEmail: ($email = $this->stringOf($user['email'] ?? null)) !== '' ? $email : null,
            authorName: ($name = $this->stringOf($user['username'] ?? null)) !== '' ? $this->sanitizer->line($name, 120) : null,
            createdAt: $this->date($row['date'] ?? null) ?? new DateTimeImmutable('@0'),
            inlineImageUrls: $this->imageUrls($body),
            author: $this->mapper->personRef($user),
            parentId: $parentId,
            attachments: $this->commentAttachments($segments),
            assignee: $assignee,
            resolved: $assignee instanceof RemotePersonRef && is_bool($row['resolved'] ?? null) ? $row['resolved'] : null,
            updatedAt: $this->date($row['date_updated'] ?? null),
        );
    }

    /**
     * Since 1.27.0: the attachment segments of a comment (`{type: attachment, attachment: {id, title, url, size,
     * mimetype}}`; unconfirmed against the live API).
     *
     * @param array<mixed> $segments
     *
     * @return list<RemoteAttachment>
     */
    private function commentAttachments(array $segments): array
    {
        $files = [];
        foreach ($segments as $segment) {
            if (!is_array($segment) || ($segment['type'] ?? null) !== 'attachment' || !is_array($segment['attachment'] ?? null)) {
                continue;
            }
            $files = [...$files, ...$this->attachments(['attachments' => [$segment['attachment']]])];
        }

        return $files;
    }

    /**
     * @param array<mixed> $row
     *
     * @return list<RemoteAttachment>
     */
    private function attachments(array $row): array
    {
        $files = [];
        foreach ($this->items($row, 'attachments') as $file) {
            $id  = $this->itemId($file);
            $url = $this->stringOf($file['url'] ?? null);
            if ($id === '' || $url === '') {
                continue;
            }
            // Unconfirmed against the live API (owner decision 2026-11-04); validated by Beacon after deploy. Every field but id and url may be missing.
            $files[] = new RemoteAttachment(
                id: $id,
                name: $this->stringOf($file['title'] ?? $file['name'] ?? null) ?: 'file',
                size: $this->count($file['size'] ?? null),
                mime: ($mime = $this->stringOf($file['mimetype'] ?? null)) !== '' ? $mime : null,
                url: $url,
                updatedAt: $this->date($file['date'] ?? null),
            );
        }

        return $files;
    }

    /**
     * @param array<mixed> $row
     */
    private function customFields(array $row): ?string
    {
        $lines = [];
        foreach ($this->items($row, 'custom_fields') as $field) {
            $name  = $this->sanitizer->line($this->stringOf($field['name'] ?? null), 120);
            $value = $field['value'] ?? null;
            if ($name === '' || $value === null || $value === '' || $value === [] || in_array($this->fieldParser->typeOf($this->stringOf($field['type'] ?? null))->value, $this->excludedFieldTypes, true)) {
                continue;
            }
            // Audit GWd-L4: a people value without e-mails or other personal keys.
            if (is_array($value) && $this->fieldParser->typeOf($this->stringOf($field['type'] ?? null)) === SyncFieldType::People) {
                $value = implode(', ', array_map(static fn (RemotePersonRef $user): string => $user->name, $this->mapper->personRefs($value)));
            }
            $text    = is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $lines[] = sprintf('- **%s**: %s', $name, $this->sanitizer->line($text, 500));
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function imageUrls(string $markdown): array
    {
        preg_match_all('/!\[[^\]\n]*\]\(\s*<?([^)\s>]+)/', $markdown, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<RemoteContainer>
     */
    private function spaces(ProviderCredential $credential, string $workspaceId, string $parentId): array
    {
        $items = [];
        foreach ($this->items($this->get($credential, sprintf('team/%s/space', $workspaceId), ['archived' => 'false']), 'spaces') as $space) {
            $items[] = new RemoteContainer('s:' . $this->itemId($space), $this->name($space), ContainerType::Space, $parentId, false, true);
        }

        return $items;
    }

    /**
     * @return list<RemoteContainer>
     */
    private function folders(ProviderCredential $credential, string $spaceId, string $parentId): array
    {
        $items = [];
        foreach ($this->items($this->get($credential, sprintf('space/%s/folder', $spaceId), ['archived' => 'false']), 'folders') as $folder) {
            $items[] = new RemoteContainer('f:' . $this->itemId($folder), $this->name($folder), ContainerType::Folder, $parentId, false, true);
        }

        return $items;
    }

    /**
     * @return list<RemoteContainer>
     */
    private function lists(ProviderCredential $credential, string $path, string $parentId): array
    {
        $items = [];
        foreach ($this->items($this->get($credential, $path, ['archived' => 'false']), 'lists') as $list) {
            $items[] = new RemoteContainer($this->itemId($list), $this->name($list), ContainerType::List, $parentId, true, false, $this->count($list['task_count'] ?? null));
        }

        return $items;
    }

    /**
     * Since 1.25: the members of one workspace as [user, role] (E1).
     *
     * @return list<array{0: array<mixed>, 1: RemoteRole|null}>
     */
    private function members(ProviderCredential $credential, string $workspaceId): array
    {
        $members = [];
        foreach ($this->items($this->get($credential, 'team'), 'teams') as $team) {
            if ($this->itemId($team) !== $workspaceId) {
                continue;
            }
            foreach ($this->items($team, 'members') as $member) {
                $user = is_array($member['user'] ?? null) ? $member['user'] : [];
                // Unconfirmed: the role sits on the user (else on the member); a missing or unknown role is null.
                $role      = $user['role'] ?? $member['role'] ?? null;
                $members[] = [$user, is_numeric($role) ? (self::ROLES[(int) $role] ?? null) : null];
            }
        }

        return $members;
    }

    /**
     * Since 1.25: one time entry (E7 row or E8 interval); null without an id, a person or a start. Milliseconds are
     * floored to seconds; a negative duration is a running timer; `billable` only when it is a JSON bool.
     *
     * @param array<mixed> $row
     */
    private function timeEntry(array $row, ?RemotePersonRef $person, ?string $taskId): ?RemoteTimeEntry
    {
        $id    = $this->stringOf($row['id'] ?? null);
        $start = $this->date($row['start'] ?? null);
        if (preg_match(self::ENTRY_ID_PATTERN, $id) !== 1 || !$person instanceof RemotePersonRef || !$start instanceof DateTimeImmutable) {
            return null;
        }
        $duration = is_numeric($row['duration'] ?? null) ? (int) $row['duration'] : 0;
        $tags     = [];
        foreach (array_slice($this->items($row, 'tags'), 0, 20) as $tag) {
            $name = $this->sanitizer->line($this->stringOf($tag['name'] ?? null), 64);
            if ($name !== '') {
                $tags[] = $name;
            }
        }

        return new RemoteTimeEntry(
            id: $id,
            taskId: $taskId,
            person: $person,
            start: $start,
            end: $duration < 0 ? null : $this->date($row['end'] ?? null),
            durationSeconds: $duration < 0 ? -1 : intdiv($duration, 1000),
            running: $duration < 0,
            billable: is_bool($row['billable'] ?? null) ? $row['billable'] : null,
            description: $this->description($row['description'] ?? null),
            tags: $tags,
            remoteUpdatedAt: $this->date($row['at'] ?? null),
        );
    }

    /**
     * Since 1.25: a time-entry description as plain text, kept whole (owner decision: no length cap) — line breaks
     * normalised to LF, other controls and bidi overrides removed, trimmed; white space only → null.
     */
    private function description(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim((string) preg_replace(self::DESCRIPTION_STRIP, '', str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], mb_scrub($value, 'UTF-8'))));

        return $text === '' ? null : $text;
    }

    /**
     * Since 1.25: the filter of the workspace task endpoint for a folder or a space.
     *
     * @return array<string, string>
     */
    private function sourceFilter(RemoteSource $source): array
    {
        return [($source->location->kind === RemoteLocationKind::Folder ? 'project_ids[]' : 'space_ids[]') => $this->checkId($source->location->id)];
    }

    /** Since 1.25: the workspace of a source (given, else looked up). */
    private function sourceWorkspace(ProviderCredential $credential, RemoteSource $source): string
    {
        $workspaceId = $source->workspaceId ?? $this->workspaceOf($credential, $source->location->containerId());
        if ($workspaceId === null) {
            throw new ProviderException(ProviderErrorCode::NotFound);
        }

        return $this->checkId($workspaceId);
    }

    /**
     * Since 1.25: the `statuses` of a list, folder or space answer, by `orderindex`.
     *
     * @param array<mixed> $data
     *
     * @return list<RemoteStatus>
     */
    private function statuses(array $data): array
    {
        $statuses = [];
        foreach ($this->items($data, 'statuses') as $index => $row) {
            $status = $this->status($row, $index);
            if ($status instanceof RemoteStatus) {
                $statuses[] = $status;
            }
        }
        usort($statuses, static fn (RemoteStatus $a, RemoteStatus $b): int => $a->position <=> $b->position);

        return $statuses;
    }

    /**
     * Since 1.25: one status ({status, type, color, orderindex}); null without a name.
     *
     * @param array<mixed> $row
     */
    private function status(array $row, int $fallbackPosition): ?RemoteStatus
    {
        $name = $this->sanitizer->line($this->stringOf($row['status'] ?? $row['name'] ?? null), 255);
        if ($name === '') {
            return null;
        }
        $color = $this->stringOf($row['color'] ?? null);

        return new RemoteStatus(
            name: $name,
            type: RemoteStatusType::tryFrom(mb_strtolower($this->stringOf($row['type'] ?? null))),
            color: preg_match('/^#[0-9A-Fa-f]{3,8}$/D', $color) === 1 ? $color : null,
            position: is_numeric($row['orderindex'] ?? null) ? (int) $row['orderindex'] : $fallbackPosition,
        );
    }

    /**
     * Since 1.25: the checklists of a task, by `orderindex`; nested items are flattened in tree order (parent, then
     * its children) as siblings.
     *
     * @return list<RemoteChecklist>
     */
    private function checklists(mixed $value): array
    {
        $checklists = [];
        $budget     = self::MAX_CHECKLIST_ITEMS;
        $rows       = is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
        usort($rows, fn (array $a, array $b): int => $this->order($a) <=> $this->order($b));
        foreach ($rows as $position => $row) {
            $id = $this->itemId($row);
            if ($id === '') {
                continue;
            }
            /** @var array<string, list<array<mixed>>> $children parent item id ('' = top) => items */
            $children = [];
            $known    = [];
            foreach ($this->items($row, 'items') as $item) {
                $itemId = $this->itemId($item);
                if ($itemId !== '') {
                    $known[$itemId] = $item;
                }
            }
            foreach ($known as $itemId => $item) {
                $parent                                                                   = $this->stringOf($item['parent'] ?? null);
                $children[isset($known[$parent]) && $parent !== $itemId ? $parent : ''][] = $item;
            }
            $items = [];
            $this->flatten($children, '', null, $items, [], $budget);
            $checklists[] = new RemoteChecklist($id, $this->sanitizer->line($this->stringOf($row['name'] ?? null), 120), $position, $items);
        }

        return $checklists;
    }

    /**
     * @param array<string, list<array<mixed>>> $children
     * @param list<RemoteChecklistItem> $items
     * @param array<string, true> $path the ancestors (a cycle stops)
     */
    private function flatten(array $children, string $parentKey, ?string $parentId, array &$items, array $path, int &$budget): void
    {
        $level = $children[$parentKey] ?? [];
        usort($level, fn (array $a, array $b): int => $this->order($a) <=> $this->order($b));
        foreach ($level as $item) {
            $id = $this->itemId($item);
            if (isset($path[$id]) || $budget <= 0) {
                continue;
            }
            --$budget;
            $items[] = new RemoteChecklistItem(
                id: $id,
                name: $this->sanitizer->line($this->stringOf($item['name'] ?? null), 255),
                position: count($items),
                resolved: ($item['resolved'] ?? false) === true,
                assignee: $this->mapper->personRef($item['assignee'] ?? null),
                parentItemId: $parentId,
            );
            $this->flatten($children, $id, $id, $items, $path + [$id => true], $budget);
        }
    }

    /**
     * @param array<mixed> $row
     */
    private function order(array $row): int
    {
        return is_numeric($row['orderindex'] ?? null) ? (int) $row['orderindex'] : 0;
    }

    /**
     * Since 1.25: every list the token can see (with its breadcrumb), within the call budget; cached per connection
     * reference when a pool is configured.
     *
     * @return list<RemoteContainer>
     */
    private function catalog(ProviderCredential $credential): array
    {
        $item = $this->lookupCache?->getItem('nowo_task_board.clickup.lists.' . hash('sha256', $credential->connectionRef));
        if ($item instanceof CacheItemInterface && $item->isHit() && is_array($cached = $item->get())) {
            $lists = [];
            foreach ($cached as $row) {
                if (is_array($row) && is_string($row['id'] ?? null) && is_string($row['name'] ?? null) && is_array($row['path'] ?? null)) {
                    $lists[] = new RemoteContainer($row['id'], $row['name'], ContainerType::List, null, true, false, null, array_values(array_filter($row['path'], is_string(...))));
                }
            }

            return $lists;
        }
        $calls = 0;
        $lists = [];
        $call  = function (string $path, array $query = []) use ($credential, &$calls): ?array {
            if ($calls >= self::SEARCH_CALL_BUDGET) {
                return null;
            }
            ++$calls;

            return $this->get($credential, $path, $query);
        };
        foreach ($this->items($call('team') ?? ['teams' => []], 'teams') as $team) {
            $teamId = $this->itemId($team);
            $spaces = $teamId === '' ? [] : $this->items($call(sprintf('team/%s/space', $teamId), ['archived' => 'false']) ?? ['spaces' => []], 'spaces');
            foreach ($spaces as $space) {
                $spaceId = $this->itemId($space);
                if ($spaceId === '') {
                    continue;
                }
                foreach ($this->items($call(sprintf('space/%s/list', $spaceId), ['archived' => 'false']) ?? ['lists' => []], 'lists') as $list) {
                    $lists[] = [$list, [$this->name($space)]];
                }
                foreach ($this->items($call(sprintf('space/%s/folder', $spaceId), ['archived' => 'false']) ?? ['folders' => []], 'folders') as $folder) {
                    // The folder answer carries its lists; no call per folder.
                    foreach ($this->items($folder + ['lists' => []], 'lists') as $list) {
                        $lists[] = [$list, [$this->name($space), $this->name($folder)]];
                    }
                }
            }
        }
        $found = [];
        $rows  = [];
        foreach ($lists as [$list, $path]) {
            $id = $this->itemId($list);
            if ($id !== '') {
                $found[] = new RemoteContainer($id, $this->name($list), ContainerType::List, null, true, false, $this->count($list['task_count'] ?? null), $path);
                $rows[]  = ['id' => $id, 'name' => $this->name($list), 'path' => $path];
            }
        }
        if ($item instanceof CacheItemInterface && $this->lookupCache instanceof CacheItemPoolInterface) {
            $this->lookupCache->save($item->set($rows)->expiresAfter(self::SEARCH_CACHE_TTL));
        }

        return $found;
    }

    /** Since 1.25: lower case without accents (search matching). */
    private function fold(string $text): string
    {
        $text = mb_strtolower(trim($text));
        if (class_exists(Normalizer::class)) {
            $text = (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($text, Normalizer::FORM_D));
        }

        return $text;
    }

    /**
     * Since 1.25: an answer, or null when ClickUp says the resource is gone (404 / 410).
     *
     * @param array<string, scalar> $query
     *
     * @return array<mixed>|null
     */
    private function getOrNull(ProviderCredential $credential, string $path, array $query = []): ?array
    {
        try {
            return $this->get($credential, $path, $query);
        } catch (ProviderException $exception) {
            if ($exception->errorCode === ProviderErrorCode::NotFound) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    private function get(ProviderCredential $credential, string $path, array $query = []): array
    {
        return $this->http->getJson(self::BASE_URI . $path, $query, ['Authorization' => $credential->reveal()]);
    }

    /**
     * The array items of `$data[$key]` (anything else is skipped).
     *
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    private function items(array $data, string $key): array
    {
        if (!is_array($data[$key] ?? null)) {
            $this->unexpected($key, $data);

            return [];
        }
        $items = [];
        foreach ($data[$key] as $item) {
            if (is_array($item)) {
                $items[] = $item;
            } else {
                $this->unexpected($key . '[]', $data);
            }
        }

        return $items;
    }

    /**
     * Whether a task page is the last one. Unconfirmed against the live API (owner decision 2026-11-04); validated by Beacon after deploy. `last_page` is used when the answer has it; without it the paging
     * goes on until an empty page (a short page is not trusted to be the last: the page size is not confirmed either).
     * The run's page caps (audit EG-L2) bound it in every case.
     *
     * @param array<mixed> $data
     */
    private function lastPage(array $data, int $rows): bool
    {
        if (array_key_exists('last_page', $data)) {
            return $data['last_page'] === true || $rows === 0;
        }
        $this->unexpected('last_page', $data);

        return $rows === 0;
    }

    /**
     * An answer that does not have the expected shape: logged at debug level with its keys only, never a value. Since
     * audit EG-I12 each key loses its control characters and is cut to 64 bytes (at most 30 keys).
     *
     * @param array<mixed> $data
     */
    private function unexpected(string $what, array $data): void
    {
        $this->logger->debug('TaskBoard sync: unexpected ClickUp answer shape', [
            'bundle'   => 'nowo_task_board',
            'provider' => self::ID,
            'field'    => $what,
            'keys'     => array_map(
                static fn (int|string $key): string => mb_strcut((string) preg_replace('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', '', (string) $key), 0, 64, 'UTF-8'),
                array_slice(array_keys($data), 0, 30),
            ),
        ]);
    }

    /**
     * @param array<mixed> $item
     */
    private function itemId(array $item): string
    {
        $id = $this->stringOf($item['id'] ?? null);

        return preg_match(self::ID_PATTERN, $id) === 1 ? $id : '';
    }

    /**
     * @param array<mixed> $item
     */
    private function name(array $item): string
    {
        return $this->sanitizer->line($this->stringOf($item['name'] ?? null), 255);
    }

    private function checkId(string $id): string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new ProviderException(ProviderErrorCode::NotFound);
        }

        return $id;
    }

    public function fieldCoverage(): array
    {
        return self::COVERAGE;
    }

    /**
     * Since 1.27.0 (TB-SYNC-3): `GET task/{id}/time_in_status` — the statuses the task went through, oldest first, the
     * current one last; null when ClickUp does not serve it (the Time in Status ClickApp is off: 404 / 410).
     * Unconfirmed against the live API; validated by Beacon after deploy.
     */
    public function fetchStatusHistory(ProviderCredential $credential, string $taskId): ?array
    {
        $data = $this->getOrNull($credential, sprintf('task/%s/time_in_status', $this->checkId($taskId)));
        if ($data === null) {
            return null;
        }
        $current = $this->statusTime($data['current_status'] ?? null, true);
        $history = [];
        foreach (is_array($data['status_history'] ?? null) ? array_slice($data['status_history'], 0, 100) : [] as $row) {
            $status = $this->statusTime($row, false);
            // The answer may list the current status among the others: it goes last, once.
            if ($status instanceof RemoteStatusTime && $status->status !== $current?->status) {
                $history[] = $status;
            }
        }

        return $current instanceof RemoteStatusTime ? [...$history, $current] : $history;
    }

    private function statusTime(mixed $row, bool $current): ?RemoteStatusTime
    {
        $status = is_array($row) ? $this->sanitizer->line($this->stringOf($row['status'] ?? null), 120) : '';
        if ($status === '' || !is_array($row)) {
            return null;
        }
        $total = is_array($row['total_time'] ?? null) ? $row['total_time'] : [];

        return new RemoteStatusTime($status, $this->date($total['since'] ?? null), is_numeric($total['by_minute'] ?? null) ? max(0, (int) $total['by_minute']) : 0, $current);
    }

    /**
     * Since 1.27.0 (TB-SYNC-3): custom id, points, done / closed dates, time flags, location path, estimates per
     * assignee and recurrence. Every value is validated; what does not fit is left out.
     *
     * @param array<mixed> $row
     */
    private function details(array $row): RemoteTaskDetails
    {
        $customId = $this->stringOf($row['custom_id'] ?? null);
        $points   = $row['points'] ?? null;
        $path     = [];
        foreach (['folder', 'list'] as $key) {
            $item = $row[$key] ?? null;
            if (is_array($item) && ($item['hidden'] ?? false) !== true && ($name = $this->name($item)) !== '') {
                $path[] = $name;
            }
        }
        $estimates = [];
        foreach (is_array($row['time_estimates_by_user'] ?? null) ? array_slice($row['time_estimates_by_user'], 0, 100) : [] as $estimate) {
            // Unconfirmed against the live API (`{user, time_estimate}` in milliseconds); validated by Beacon after deploy.
            $person = is_array($estimate) ? $this->mapper->personRef($estimate['user'] ?? null) : null;
            $ms     = is_array($estimate) ? ($estimate['time_estimate'] ?? $estimate['user_time_estimate'] ?? null) : null;
            if ($person instanceof RemotePersonRef && is_numeric($ms) && (int) $ms >= 0) {
                $estimates[] = ['person' => $person, 'minutes' => intdiv((int) $ms, 60_000)];
            }
        }
        $recurrence = $row['recurrence'] ?? null;

        return new RemoteTaskDetails(
            customId: preg_match('/^[A-Za-z0-9 _.:#-]{1,64}$/D', $customId) === 1 ? $customId : null,
            points: (is_int($points) || is_float($points) || is_string($points)) && is_numeric($points) && is_finite((float) $points) && strlen((string) $points) <= 20 ? (string) (0 + $points) : null,
            doneAt: $this->date($row['date_done'] ?? null),
            closedAt: $this->date($row['date_closed'] ?? null),
            startHasTime: ($row['start_date_time'] ?? false) === true,
            dueHasTime: ($row['due_date_time'] ?? false) === true,
            path: $path,
            estimates: $estimates,
            recurrence: is_string($recurrence) && trim($recurrence) !== '' ? $this->sanitizer->line($recurrence, 200) : null,
        );
    }

    /**
     * Since 1.27.0: the keys of the answer that carry a value (`[a-z0-9_]{1,40}` only: remote keys are untrusted).
     *
     * @param array<mixed> $row
     *
     * @return list<string>
     */
    private function presentKeys(array $row): array
    {
        $keys = [];
        foreach ($row as $key => $value) {
            if (is_string($key) && preg_match('/^[a-z0-9_]{1,40}$/D', $key) === 1 && $value !== null && $value !== '' && $value !== [] && $value !== false) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Since 1.27.0: the pass of a task cursor — `<n>` the active tasks' page n, `a<n>` the archived tasks' page n.
     *
     * @return array{0: bool, 1: int}
     */
    private function pass(?string $cursor): array
    {
        $archived = $cursor !== null && str_starts_with($cursor, 'a');

        return [$archived, $this->page($archived ? substr((string) $cursor, 1) : $cursor)];
    }

    /**
     * Since 1.27.0: after the last active page comes the archived pass (`include_archived`).
     *
     * @param list<RemoteTask> $tasks
     */
    private function taskPage(array $tasks, bool $archived, int $page, bool $last): RemoteTaskPage
    {
        $next = match (true) {
            !$last                               => ($archived ? 'a' : '') . ($page + 1),
            !$archived && $this->includeArchived => 'a0',
            default                              => null,
        };

        return new RemoteTaskPage($tasks, $next, $next === null, $last && $next !== null);
    }

    private function page(?string $cursor): int
    {
        return $cursor !== null && ctype_digit($cursor) ? (int) $cursor : 0;
    }

    private function count(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    /** A ClickUp date: milliseconds since the epoch. */
    private function date(mixed $value): ?DateTimeImmutable
    {
        $value = $this->stringOf($value);
        if ($value === '' || !ctype_digit($value) || strlen($value) > 15) {
            return null;
        }
        // Audit GW-L7: a date outside 2000-2100 is not one (MySQL refuses some, SQLite misreads them).
        $seconds = strlen($value) > 3 ? (int) substr($value, 0, -3) : 0;

        return $seconds >= self::MIN_DATE && $seconds < self::MAX_DATE ? new DateTimeImmutable('@' . $seconds) : null;
    }

    private function stringOf(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }
}
