<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Exception\RateLimitedException;
use Nowo\TaskBoardBundle\Sync\Http\ProviderHttpClient;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpTaskProvider;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklistItem;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocation;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocationKind;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccessMode;
use Nowo\TaskBoardBundle\Sync\Provider\TimeEntryQuery;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_map;
use function count;
use function explode;
use function file;
use function file_get_contents;
use function hash;
use function is_string;
use function iterator_to_array;
use function json_encode;
use function parse_str;
use function parse_url;
use function str_contains;
use function str_repeat;
use function strlen;
use function substr;
use function trim;

use const FILE_IGNORE_NEW_LINES;

/**
 * Since 1.25: the ClickUp adapter's people (E1 / E2 / E3), tracked time (E7 / E8), task rows with creator, watchers,
 * checklists, status types and lists, folder / space sources, statuses and list lookups — against hand-written fake
 * fixtures. Every call is a GET to the constant base URI.
 */
final class ClickUpPeopleTimeProviderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/ClickUp/';

    /** @var list<array{method: string, url: string, path: string, query: array<int|string, mixed>}> */
    private array $requests = [];

    /** @var array<string, MockResponse|string> */
    private array $routes = [];

    private ProviderCredential $credential;

    protected function setUp(): void
    {
        $this->credential = new ProviderCredential('pk_test_token', 'conn-1');
    }

    public function testPeopleRolesAndTimeAccess(): void
    {
        $provider = $this->provider(['team' => 'team_people.json', 'user' => 'user.json']);
        self::assertTrue($provider->capabilities()->people);
        self::assertTrue($provider->capabilities()->timeEntries);
        self::assertTrue($provider->capabilities()->watchers);
        self::assertTrue($provider->capabilities()->checklists);

        $page = $provider->fetchPeople($this->credential, '9000001');
        self::assertNull($page->nextCursor);
        self::assertSame(['1000001', '1000002', '1000003', '1000004', '1000005', '1000006'], array_map(static fn (RemotePerson $p): string => $p->providerUserId, $page->people));
        self::assertSame([RemoteRole::Owner, RemoteRole::Admin, RemoteRole::Member, RemoteRole::Guest, RemoteRole::Member, null], array_map(static fn (RemotePerson $p): ?RemoteRole => $p->role, $page->people));
        self::assertSame('user1@example.test', $page->people[0]->email);
        self::assertNull($page->people[4]->email, 'a member without e-mail');
        self::assertNull($page->people[5]->email, 'an invalid e-mail is dropped');
        self::assertSame('User 5', $page->people[4]->name);
        self::assertNull($page->people[0]->avatarUrl);
        self::assertSame('1000001', $page->people[0]->ref()->providerUserId);
        self::assertSame([], $provider->fetchPeople($this->credential, '9999999')->people, 'an unknown workspace is empty');

        $access = $provider->timeAccess($this->credential, '9000001');
        self::assertSame(TimeAccessMode::Admin, $access->mode);
        self::assertSame('1000002', $access->tokenUserId);
        self::assertSame(TimeAccessMode::Member, $provider->timeAccess($this->credential, '9000002')->mode, 'not a member of that workspace');

        $this->assertOnlyGets();
    }

    public function testTimeAccessOfMembersAndOddAnswers(): void
    {
        $team     = ['teams' => [['id' => '9000001', 'members' => [['user' => ['id' => 1000002, 'username' => 'User 2', 'role' => 3, 'profilePicture' => 'https://example.test/a.png']], 'junk']]]];
        $provider = $this->provider(['team' => new MockResponse((string) json_encode($team)), 'user' => 'user.json']);
        self::assertSame(TimeAccessMode::Member, $provider->timeAccess($this->credential, '9000001')->mode);
        self::assertSame('https://example.test/a.png', $provider->fetchPeople($this->credential, '9000001')->people[0]->avatarUrl, 'kept in the DTO only, never stored');

        $logger   = new RecordingLogger();
        $provider = $this->provider(['user' => new MockResponse('{"user": {"id": "bad id!"}}')], $logger);
        $access   = $provider->timeAccess($this->credential, '9000001');
        self::assertSame(TimeAccessMode::Member, $access->mode);
        self::assertNull($access->tokenUserId);
        self::assertNotSame([], $logger->records);
    }

    public function testWorkspaceOfListsFoldersAndSpaces(): void
    {
        $routes = [
            'list/900200'        => 'list_900200.json',
            'folder/90002'       => 'folder_90002.json',
            'team'               => new MockResponse((string) json_encode(['teams' => [['id' => 'bad id!'], ['id' => '9000002'], ['id' => '9000001']]])),
            'team/9000002/space' => new MockResponse('{"spaces": [{"id": "70001"}]}'),
            'team/9000001/space' => 'team_9000001_space.json',
            'list/900999'        => new MockResponse('{"id": "900999", "space": {}}'),
        ];
        $provider = $this->provider($routes);
        self::assertSame('9000001', $provider->workspaceOf($this->credential, '900200'));
        self::assertSame('9000001', $provider->workspaceOf($this->credential, 'f:90002'));
        self::assertSame('9000001', $provider->workspaceOf($this->credential, 's:90001'));
        self::assertNull($provider->workspaceOf($this->credential, 's:12345'), 'no workspace has that space');
        self::assertNull($provider->workspaceOf($this->credential, 'w:9000001'), 'not a source');
        self::assertNull($provider->workspaceOf($this->credential, '404404'), 'a missing list');
        self::assertNull($provider->workspaceOf($this->credential, '900999'), 'an answer without its space');

        $provider = $this->provider(['list/L' => new MockResponse('', ['http_code' => 403])]);
        $this->expectException(ProviderException::class);
        $provider->workspaceOf($this->credential, 'L');
    }

    public function testAdminTimeEntriesAreReadPerWindowAndChunk(): void
    {
        $provider = $this->provider(['team/9000001/time_entries' => 'team_9000001_time_entries_admin.json']);
        $from     = new DateTimeImmutable('@1793000000');
        $to       = new DateTimeImmutable('@1795592000');
        $entries  = iterator_to_array($provider->fetchTimeEntries($this->credential, new TimeEntryQuery('9000001', RemoteLocation::list('900200'), $from, $to, ['1000002', '1000003', 'bad id!'])), false);

        $query = $this->requests[0]['query'];
        self::assertSame('1793000000000', $query['start_date']);
        self::assertSame('1795592000000', $query['end_date']);
        self::assertSame('900200', $query['list_id']);
        self::assertSame('1000002,1000003', $query['assignee'], 'only directory ids, never anything else');
        self::assertSame('false', $query['include_task_tags']);
        self::assertSame('false', $query['include_location_names']);

        self::assertCount(4, $entries, 'the entry without a person is dropped');
        [$first, $second, $running, $taskless] = $entries;
        self::assertSame('4000000000000000001', $first->id);
        self::assertSame('86a1x1', $first->taskId);
        self::assertSame('1000002', $first->person->providerUserId);
        self::assertSame(3600, $first->durationSeconds, 'milliseconds floored');
        self::assertSame(1793003600, $first->end?->getTimestamp());
        self::assertTrue($first->billable);
        self::assertSame("Line one\nLine two reversed", $first->description, 'line breaks kept; bidi and controls removed');
        self::assertSame(['design'], $first->tags);
        self::assertSame(1793003600, $first->remoteUpdatedAt?->getTimestamp());
        self::assertFalse($second->billable);
        self::assertNull($second->description, 'white space only');
        self::assertTrue($running->running);
        self::assertNull($running->end);
        self::assertNull($running->billable, 'billable only when a JSON bool');
        self::assertNull($taskless->taskId);
        $this->assertOnlyGets();
    }

    public function testMemberModeSendsNoAssigneeAndLongDescriptionsAreKept(): void
    {
        $long     = str_repeat('Ünïcode text ', 2000);
        $rows     = ['data' => [['id' => '1', 'task' => ['id' => 'T'], 'user' => ['id' => 1, 'username' => 'User 1'], 'start' => '1793000000000', 'end' => '1793000060000', 'duration' => '60000', 'description' => $long, 'tags' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c'], ['name' => 'd'], ['name' => 'e'], ['name' => 'f'], ['name' => 'g'], ['name' => 'h'], ['name' => 'i'], ['name' => 'j'], ['name' => 'k'], ['name' => 'l'], ['name' => 'm'], ['name' => 'n'], ['name' => 'o'], ['name' => 'p'], ['name' => 'q'], ['name' => 'r'], ['name' => 's'], ['name' => 't'], ['name' => 'u']]], ['id' => 'bad id!', 'user' => ['id' => 1], 'start' => '1'], ['id' => '2', 'user' => ['id' => 1], 'start' => 'x'], ['id' => '3', 'user' => ['id' => 1], 'start' => '1793000000000', 'description' => ['not text']]]];
        $provider = $this->provider([
            'team/9000001/time_entries' => new MockResponse((string) json_encode($rows)),
        ]);
        $entries = iterator_to_array($provider->fetchTimeEntries($this->credential, new TimeEntryQuery('9000001', new RemoteLocation(RemoteLocationKind::Space, '90001'), new DateTimeImmutable('@1793000000'), new DateTimeImmutable('@1793086400'))), false);
        self::assertArrayNotHasKey('assignee', $this->requests[0]['query']);
        self::assertSame('90001', $this->requests[0]['query']['space_id']);
        self::assertCount(2, $entries);
        self::assertSame(trim($long), $entries[0]->description, 'never truncated');
        self::assertCount(20, $entries[0]->tags);
        self::assertNull($entries[1]->description);
        self::assertSame(0, $entries[1]->durationSeconds);

        $member = $this->provider(['team/9000001/time_entries' => 'team_9000001_time_entries_member.json']);
        $own    = iterator_to_array($member->fetchTimeEntries($this->credential, new TimeEntryQuery('9000001', new RemoteLocation(RemoteLocationKind::Folder, '90002'), new DateTimeImmutable('@1793000000'), new DateTimeImmutable('@1793086400'), [])), false);
        self::assertSame('90002', $this->requests[0]['query']['folder_id']);
        self::assertArrayNotHasKey('assignee', $this->requests[0]['query'], 'an empty list sends no assignee');
        self::assertSame('Own entry', $own[0]->description);

        $this->expectException(ProviderException::class);
        $member->fetchTimeEntries($this->credential, new TimeEntryQuery('../x', RemoteLocation::list('1'), new DateTimeImmutable(), new DateTimeImmutable()));
    }

    public function testRateLimitPausesTheRun(): void
    {
        $headers = [];
        foreach (file(self::FIXTURES . 'time_entries_429.headers', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[]      = trim($name) . ': ' . trim($value);
            }
        }
        $provider = $this->provider(['team/9000001/time_entries' => new MockResponse('{}', ['http_code' => 429, 'response_headers' => $headers])]);
        try {
            iterator_to_array($provider->fetchTimeEntries($this->credential, new TimeEntryQuery('9000001', RemoteLocation::list('900200'), new DateTimeImmutable('@1793000000'), new DateTimeImmutable('@1793086400'))));
            self::fail('rate limited');
        } catch (RateLimitedException $exception) {
            self::assertSame(120, $exception->retryAfterSeconds);
        }
    }

    public function testLegacyTaskIntervals(): void
    {
        $provider = $this->provider([
            'task/86a1x1/time' => 'task_86a1x1_time.json',
            'task/GONE/time'   => new MockResponse('', ['http_code' => 410]),
            'task/DENY/time'   => new MockResponse('', ['http_code' => 403]),
        ]);
        $intervals = iterator_to_array($provider->fetchTaskTimeIntervals($this->credential, '86a1x1') ?? [], false);
        self::assertCount(2, $intervals, 'an interval without an id is dropped');
        self::assertSame('86a1x1', $intervals[0]->taskId);
        self::assertSame(3600, $intervals[0]->durationSeconds);
        self::assertSame('1000002', $intervals[1]->person->providerUserId);
        self::assertNull($provider->fetchTaskTimeIntervals($this->credential, '404404'), '404: no longer served');
        self::assertNull($provider->fetchTaskTimeIntervals($this->credential, 'GONE'), '410: no longer served');
        $this->expectException(ProviderException::class);
        $provider->fetchTaskTimeIntervals($this->credential, 'DENY');
    }

    /** Audit HY-B: an all-digit item id is an integer key. An item that names itself as parent is still a top item, not lost. */
    public function testAnAllDigitChecklistItemWithItselfAsParentStaysOnTheList(): void
    {
        $provider = $this->provider(['list/900200/task' => 'list_900200_task_digit_checklist.json']);
        $items    = $provider->fetchTasks($this->credential, '900200', null, null)->tasks[0]->checklists[0]->items;

        self::assertSame(['5', '6'], array_map(static fn (RemoteChecklistItem $item): string => $item->id, $items));
        self::assertSame([null, '5'], array_map(static fn (RemoteChecklistItem $item): ?string => $item->parentItemId, $items));
    }

    public function testTaskRowsCarryPeopleChecklistsStatusesAndLists(): void
    {
        $provider       = $this->provider(['list/900200/task' => 'list_900200_task_people.json']);
        $page           = $provider->fetchTasks($this->credential, '900200', null, null);
        [$task, $plain] = $page->tasks;

        self::assertSame('1000001', $task->task->creator?->providerUserId);
        self::assertSame(['1000002', '1000005'], array_map(static fn (RemotePersonRef $r): string => $r->providerUserId, $task->task->assigneeRefs));
        self::assertTrue($task->watchersKnown);
        self::assertSame(['1000003'], array_map(static fn (RemotePersonRef $r): string => $r->providerUserId, $task->watchers));
        self::assertTrue($task->checklistsKnown);
        self::assertSame(['cl-1', 'cl-2'], [$task->checklists[0]->id, $task->checklists[1]->id], 'by orderindex');
        self::assertSame('Review', $task->checklists[0]->name);
        $items = $task->checklists[0]->items;
        self::assertSame(['it-11', 'it-111', 'it-1111', 'it-12'], array_map(static fn (RemoteChecklistItem $i): string => $i->id, $items), 'nested items flattened in tree order');
        self::assertSame([0, 1, 2, 3], array_map(static fn (RemoteChecklistItem $i): int => $i->position, $items));
        self::assertSame([null, 'it-11', 'it-111', null], array_map(static fn (RemoteChecklistItem $i): ?string => $i->parentItemId, $items));
        self::assertTrue($items[0]->resolved);
        self::assertSame('1000004', $items[0]->assignee?->providerUserId);
        self::assertSame(5400, $task->timeSpentSeconds);
        $status = $task->status;
        self::assertNotNull($status);
        self::assertSame('complete', $status->name);
        self::assertSame(RemoteStatusType::Closed, $status->type);
        self::assertTrue($status->type->isDone());
        self::assertSame('#6bc950', $status->color);
        self::assertSame(3, $status->position);
        self::assertSame(['900200', 'List 2'], [$task->listId, $task->listName]);

        self::assertFalse($plain->watchersKnown, 'no watchers key: unknown, not empty');
        self::assertFalse($plain->checklistsKnown);
        self::assertNull($plain->timeSpentSeconds);
        self::assertNull($plain->listId);
        self::assertSame(RemoteStatusType::Custom, $plain->status?->type);
    }

    public function testTaskDetailFallback(): void
    {
        $provider = $this->provider(['task/86a1x2' => 'task_86a1x2.json']);
        $detail   = $provider->fetchTaskDetail($this->credential, '86a1x2');
        self::assertInstanceOf(RemoteTask::class, $detail);
        self::assertSame('86a1x2', $detail->task->externalId);
        self::assertTrue($detail->watchersKnown);
        self::assertTrue($detail->checklistsKnown);
        self::assertSame('true', $this->lastRequest()['query']['include_markdown_description'] ?? null);
        self::assertNull($provider->fetchTaskDetail($this->credential, '404404'), 'gone: null');
        $this->assertOnlyGets();
    }

    public function testChecklistEdgeCases(): void
    {
        $row = ['id' => 'T1', 'name' => 'Edge', 'date_updated' => '1793000000000', 'checklists' => [
            'junk',
            ['name' => 'no id'],
            ['id' => 'cl', 'name' => "Bad\u{202E}name", 'items' => [
                ['id' => 'a', 'name' => 'Self parent', 'parent' => 'a'],
                ['id' => 'b', 'name' => 'Orphan', 'parent' => 'missing'],
                ['id' => 'c', 'name' => 'Cycle 1', 'parent' => 'd'],
                ['id' => 'd', 'name' => 'Cycle 2', 'parent' => 'c'],
                ['name' => 'No id'],
            ]],
        ], 'status' => ['status' => '', 'type' => 'weird'], 'list' => ['id' => 'bad id!', 'name' => ''], 'time_spent' => -5];
        $provider = $this->provider(['list/L/task' => new MockResponse((string) json_encode(['tasks' => [$row], 'last_page' => true]))]);
        $task     = $provider->fetchTasks($this->credential, 'L', null, null)->tasks[0];
        self::assertCount(1, $task->checklists);
        self::assertSame('Badname', $task->checklists[0]->name);
        self::assertSame(['a', 'b'], array_map(static fn (RemoteChecklistItem $i): string => $i->id, $task->checklists[0]->items), 'self parents and orphans are top items; a cycle is dropped');
        self::assertNull($task->status, 'a status without a name');
        self::assertNull($task->listId);
        self::assertNull($task->listName);
        self::assertNull($task->timeSpentSeconds);

        $many = [];
        for ($i = 0; $i < 1005; ++$i) {
            $many[] = ['id' => 'i' . $i, 'name' => 'Item ' . $i, 'orderindex' => $i];
        }
        $provider = $this->provider(['list/L/task' => new MockResponse((string) json_encode(['tasks' => [['id' => 'T2', 'name' => 'Many', 'checklists' => [['id' => 'c1', 'items' => $many]]]], 'last_page' => true]))]);
        self::assertCount(1000, $provider->fetchTasks($this->credential, 'L', null, null)->tasks[0]->checklists[0]->items, 'capped');
    }

    public function testCommentAuthorIsAPersonRef(): void
    {
        $provider = $this->provider(['task/86a1x1/comment?' => 'task_86a1x1_comment.json']);
        $comments = iterator_to_array($provider->fetchComments($this->credential, '86a1x1'), false);
        foreach ($comments as $comment) {
            if ($comment->authorEmail !== null) {
                self::assertSame($comment->authorEmail, $comment->author?->email);
            }
        }
        self::assertNotNull($comments[1]->author);
    }

    public function testSourcesAndStatuses(): void
    {
        $provider = $this->provider([
            'list/900200'              => 'list_900200.json',
            'list/900300'              => 'list_900300.json',
            'folder/90002'             => 'folder_90002.json',
            'space/90001'              => 'space_90001.json',
            'team'                     => 'team.json',
            'team/9000001/space'       => 'team_9000001_space.json',
            'team/9000001/task?page=0' => 'team_9000001_task_page0.json',
            'team/9000001/task?page=7' => new MockResponse('{"tasks": [{"id": "x1", "name": "X"}]}'),
        ]);
        $list = $provider->findSource($this->credential, '900200');
        self::assertNotNull($list);
        self::assertSame(RemoteLocationKind::List, $list->location->kind);
        self::assertSame(['Space 1', 'Folder 1'], $list->path);
        self::assertSame(1, $list->listCount);
        self::assertSame(3, $list->openTaskCount);
        $folder = $provider->findSource($this->credential, 'f:90002');
        self::assertNotNull($folder);
        self::assertSame('Folder 1', $folder->name);
        self::assertSame(['Space 1'], $folder->path);
        self::assertSame(2, $folder->listCount);
        self::assertSame(5, $folder->openTaskCount);
        $space = $provider->findSource($this->credential, 's:90001');
        self::assertNotNull($space);
        self::assertSame([], $space->path);
        self::assertNull($space->listCount);
        self::assertNull($provider->findSource($this->credential, 'w:1'));
        self::assertNull($provider->findSource($this->credential, '404404'));
        self::assertNull($provider->findSource($this->credential, 's:404404'));

        // Statuses: list / space answers by orderindex, the folder is the union of its lists.
        self::assertSame(['to do', 'in progress', 'complete'], array_map(static fn (RemoteStatus $s): string => $s->name, $provider->listStatuses($this->credential, $space)));
        self::assertNull($provider->listStatuses($this->credential, $space)[1]->color, 'a color that is not hex');
        $union = $provider->listStatuses($this->credential, $folder);
        self::assertSame(['to do', 'done', 'review', 'closed'], array_map(static fn (RemoteStatus $s): string => $s->name, $union));
        self::assertSame([0, 1, 2, 3], array_map(static fn (RemoteStatus $s): int => $s->position, $union));
        self::assertSame(RemoteStatusType::Done, $union[1]->type);
        self::assertSame(['To Do', 'review', 'closed'], array_map(static fn (RemoteStatus $s): string => $s->name, $provider->listStatuses($this->credential, new RemoteSource(RemoteLocation::list('900300'), 'List 3'))));

        // Tasks of the space through the filtered workspace endpoint (the workspace looked up once from the space).
        $this->requests = [];
        $tasks          = $provider->fetchSourceTasks($this->credential, $space, new DateTimeImmutable('@1793000000'), null);
        self::assertTrue($tasks->lastPage);
        self::assertSame(['86a1x9', '86a1x8'], array_map(static fn (RemoteTask $t): string => $t->externalId(), $tasks->tasks));
        self::assertSame(['900300', 'List 3'], [$tasks->tasks[0]->listId, $tasks->tasks[0]->listName]);
        $last = $this->lastRequest();
        self::assertSame('team/9000001/task', $last['path']);
        self::assertSame(['90001'], $last['query']['space_ids']);
        self::assertSame('1793000000000', $last['query']['date_updated_gt']);
        self::assertSame(['86a1x9'], iterator_to_array($provider->existingSourceTaskIds($this->credential, $space), false));

        $withWorkspace = new RemoteSource(new RemoteLocation(RemoteLocationKind::Folder, '90002'), 'Folder 1', '9000001');
        $more          = $provider->fetchSourceTasks($this->credential, $withWorkspace, null, '7');
        self::assertFalse($more->lastPage);
        self::assertSame('8', $more->nextCursor);
        $last = $this->lastRequest();
        self::assertSame(['90002'], $last['query']['project_ids'], 'ClickUp names folders "projects" there');
        self::assertArrayNotHasKey('date_updated_gt', $last['query']);
        // Since 1.27.0: the archived pass of a folder or space ("a<n>" cursors).
        $archived = $provider->fetchSourceTasks($this->credential, $withWorkspace, null, 'a7');
        self::assertSame('a8', $archived->nextCursor);
        self::assertSame('true', $this->lastRequest()['query']['archived']);
        self::assertSame('7', $this->lastRequest()['query']['page']);

        // A list source keeps the 1.23 calls.
        $listProvider = $this->provider(['list/900200/task' => 'list_900200_task_page0.json']);
        self::assertCount(3, $listProvider->fetchSourceTasks($this->credential, $list, null, null)->tasks);
        self::assertCount(3, iterator_to_array($listProvider->existingSourceTaskIds($this->credential, $list), false));
        self::assertSame('list/900200/task', $this->lastRequest()['path']);
        $this->assertOnlyGets();
    }

    public function testSourceEdgeCases(): void
    {
        $tasks   = ['tasks' => [['id' => 'a', 'name' => 'A']]];
        $endless = $this->provider([
            'team/9000001/task' => new MockResponse((string) json_encode($tasks)),
            'team'              => new MockResponse('{"teams": []}'),
            'folder/90005'      => new MockResponse('{"id": "90005", "name": "F", "lists": [{"name": "no id"}, {"id": "900400", "name": "L4"}]}'),
            'list/900400'       => new MockResponse('{"id": "900400", "statuses": [{"status": "doing", "type": "custom"}]}'),
            'folder/90006'      => new MockResponse('{"id": "90006", "name": "F", "statuses": [{"status": "own", "type": "open"}]}'),
        ]);
        $space = new RemoteSource(new RemoteLocation(RemoteLocationKind::Space, '90001'), 'Space', '9000001');
        try {
            iterator_to_array($endless->existingSourceTaskIds($this->credential, $space), false);
            self::fail('endless paging');
        } catch (ProviderException $exception) {
            self::assertSame(ProviderErrorCode::ResponseTooLarge, $exception->errorCode);
        }
        self::assertSame(['doing'], array_map(static fn (RemoteStatus $s): string => $s->name, $endless->listStatuses($this->credential, new RemoteSource(new RemoteLocation(RemoteLocationKind::Folder, '90005'), 'F'))));
        self::assertSame(['own'], array_map(static fn (RemoteStatus $s): string => $s->name, $endless->listStatuses($this->credential, new RemoteSource(new RemoteLocation(RemoteLocationKind::Folder, '90006'), 'F'))));

        try {
            $endless->fetchSourceTasks($this->credential, new RemoteSource(new RemoteLocation(RemoteLocationKind::Space, '777'), 'Unknown'), null, null);
            self::fail('no workspace');
        } catch (ProviderException $exception) {
            self::assertSame(ProviderErrorCode::NotFound, $exception->errorCode);
        }
    }

    public function testListAddressesAreResolvedWithoutNetwork(): void
    {
        $provider = $this->provider([]);
        self::assertSame('900200', $provider->resolveListFromUrl('https://app.clickup.com/9000001/v/li/900200'));
        self::assertSame('900200', $provider->resolveListFromUrl(' https://app.clickup.com/v/li/900200/ '));
        self::assertSame('900200', $provider->resolveListFromUrl('https://app.clickup.com/9000001/v/l/6-900200-1'));
        self::assertSame('900200', $provider->resolveListFromUrl('https://app.clickup.com/9000001/v/l/6-900200-1?pr=1'));
        foreach ([
            'http://app.clickup.com/9000001/v/li/900200',
            'https://evil.example.test/9000001/v/li/900200',
            'https://user:pass@app.clickup.com/9000001/v/li/900200',
            'https://app.clickup.com:8443/9000001/v/li/900200',
            'https://app.clickup.com/9000001/v/b/t/900200',
            'https://app.clickup.com/9000001/v/li/../x',
            'not a url',
            'https:///v/li/1',
        ] as $url) {
            self::assertNull($provider->resolveListFromUrl($url), $url);
        }
        self::assertSame([], $this->requests);
    }

    public function testListSearchWalksTheTreeWithinBudgetAndCaches(): void
    {
        $routes = [
            'team'               => new MockResponse('{"teams": [{"id": "bad id!"}, {"id": "9000001"}]}'),
            'team/9000001/space' => new MockResponse('{"spaces": [{"name": "no id"}, {"id": "90001", "name": "Space 1"}]}'),
            'space/90001/list'   => new MockResponse('{"lists": [{"id": "900100", "name": "Incidéncias y fuegos", "task_count": 4}, {"name": "no id"}]}'),
            'space/90001/folder' => new MockResponse('{"folders": [{"id": "90002", "name": "Folder 1", "lists": [{"id": "900200", "name": "Roadmap"}]}, {"id": "90003", "name": "Empty"}]}'),
        ];
        $cache    = new ArrayAdapter();
        $provider = $this->provider($routes, cache: $cache);
        $found    = $provider->searchLists($this->credential, 'INCIDENCIAS');
        self::assertCount(1, $found);
        self::assertSame('900100', $found[0]->id);
        self::assertSame(['Space 1'], $found[0]->path);
        self::assertSame(4, $found[0]->taskCount);
        self::assertCount(4, $this->requests);

        $this->requests = [];
        $again          = $provider->searchLists($this->credential, 'road');
        self::assertSame([], $this->requests, 'cached per connection reference');
        self::assertSame(['900200'], array_map(static fn (RemoteContainer $c): string => $c->id, $again));
        self::assertSame(['Space 1', 'Folder 1'], $again[0]->path);
        self::assertCount(1, $provider->searchLists($this->credential, '', 1), 'limit');
        foreach ($cache->getValues() as $key => $value) {
            self::assertStringNotContainsString('pk_test_token', $key . json_encode($value));
        }

        // A garbled cache entry is ignored row by row.
        $garbled = new ArrayAdapter();
        $item    = $garbled->getItem('nowo_task_board.clickup.lists.' . hash('sha256', 'conn-1'));
        $garbled->save($item->set([['id' => '1', 'name' => 'Ok', 'path' => ['A', 3]], 'junk', ['id' => 2]]));
        self::assertSame(['A'], $this->provider([], cache: $garbled)->searchLists($this->credential, 'ok')[0]->path);

        // Without a cache, the walk stops at the call budget.
        $spaces = [];
        for ($i = 0; $i < 30; ++$i) {
            $spaces[] = ['id' => 's' . $i, 'name' => 'Space ' . $i];
        }
        $wide = $this->provider([
            'team'               => new MockResponse('{"teams": [{"id": "9000001"}]}'),
            'team/9000001/space' => new MockResponse((string) json_encode(['spaces' => $spaces])),
        ], wildcard: new MockResponse('{"lists": [], "folders": []}'));
        self::assertSame([], $wide->searchLists($this->credential, 'x'));
        self::assertCount(ClickUpTaskProvider::SEARCH_CALL_BUDGET, $this->requests);
    }

    /**
     * @return array{method: string, url: string, path: string, query: array<int|string, mixed>}
     */
    private function lastRequest(): array
    {
        $requests = $this->requests;
        self::assertNotSame([], $requests);

        return $requests[count($requests) - 1];
    }

    private function assertOnlyGets(): void
    {
        self::assertNotSame([], $this->requests);
        foreach ($this->requests as $request) {
            self::assertSame('GET', $request['method']);
            self::assertStringStartsWith(ClickUpTaskProvider::BASE_URI, $request['url']);
        }
    }

    /**
     * @param array<string, MockResponse|string> $routes
     */
    private function provider(array $routes, ?RecordingLogger $logger = null, ?ArrayAdapter $cache = null, ?MockResponse $wildcard = null): ClickUpTaskProvider
    {
        $this->routes   = $routes;
        $this->requests = [];
        $client         = new MockHttpClient(function (string $method, string $url) use ($wildcard): MockResponse {
            $parts = parse_url($url);
            $path  = substr((string) ($parts['path'] ?? ''), strlen('/api/v2/'));
            parse_str((string) ($parts['query'] ?? ''), $query);
            $this->requests[] = ['method' => $method, 'url' => $url, 'path' => $path, 'query' => $query];
            foreach ([$path . '?' . (isset($query['start']) ? 'start' : ''), $path . '?page=' . (is_string($query['page'] ?? null) ? $query['page'] : ''), $path] as $key) {
                if (isset($this->routes[$key])) {
                    $route = $this->routes[$key];

                    return $route instanceof MockResponse ? clone $route : new MockResponse((string) file_get_contents(self::FIXTURES . $route));
                }
            }

            return $wildcard instanceof MockResponse ? clone $wildcard : new MockResponse('{"err": "not found"}', ['http_code' => 404]);
        });

        return new ClickUpTaskProvider(new ProviderHttpClient($client, new RecordingSleeper(), new MockClock()), logger: $logger ?? new RecordingLogger(), lookupCache: $cache);
    }
}
