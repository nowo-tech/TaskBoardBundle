<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Http\ProviderHttpClient;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpTaskProvider;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusTime;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskDetails;
use Nowo\TaskBoardBundle\Sync\Provider\ReplyBudget;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function count;
use function iterator_to_array;
use function json_encode;
use function parse_str;
use function parse_url;
use function strlen;

/**
 * Since 1.23: the ClickUp v2 adapter against the (provisional) fixtures of tests/Fixtures/ClickUp — paths, query,
 * the Authorization header only to api.clickup.com, mapping and sanitizing.
 */
final class ClickUpTaskProviderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/ClickUp/';

    /** @var list<array{url: string, query: array<string, mixed>, headers: list<string>}> */
    private array $requests = [];

    /** @var array<string, MockResponse|string> path => response or fixture file */
    private array $routes = [];

    public function testTheTreeLevelByLevel(): void
    {
        $provider = $this->provider([
            'team'               => 'team.json',
            'team/9000001/space' => 'team_9000001_space.json',
            'space/90001/folder' => 'space_90001_folder.json',
            'space/90001/list'   => 'space_90001_list.json',
            'folder/90002/list'  => 'folder_90002_list.json',
            'list/900200'        => 'list_900200.json',
        ]);
        $credential = new ProviderCredential('pk_test_token', 'conn-1');

        $top = $provider->listContainers($credential);
        self::assertSame('w:9000001', $top->items[0]->id);
        self::assertSame(ContainerType::Workspace, $top->items[0]->type);
        self::assertSame('s:90001', $provider->listContainers($credential, 'w:9000001')->items[0]->id);
        $space = $provider->listContainers($credential, 's:90001')->items;
        self::assertSame(['f:90002', '900100'], [$space[0]->id, $space[1]->id]);
        self::assertTrue($space[1]->selectable);
        self::assertSame(2, $space[1]->taskCount);
        self::assertSame('900200', $provider->listContainers($credential, 'f:90002')->items[0]->id);
        $list = $provider->findContainer($credential, '900200');
        self::assertSame(['Space 1', 'Folder 1'], $list?->path);
        self::assertSame(3, $list?->taskCount);

        self::assertSame('pk_test_token', $this->requests[0]['headers']['authorization'] ?? null);
        self::assertSame('false', $this->requests[1]['query']['archived']);
        foreach ($this->requests as $request) {
            self::assertStringStartsWith('https://api.clickup.com/api/v2/', $request['url']);
        }

        self::assertNull($provider->findContainer($credential, 'bad id!'));
        self::assertNull($provider->findContainer($credential, '404404'), 'a missing list');
        $this->expectException(ProviderException::class);
        $provider->listContainers($credential, 'x:1');
    }

    public function testTasksAreMappedAndSanitized(): void
    {
        $provider = $this->provider(['list/900200/task' => 'list_900200_task_page0.json']);
        $page     = $provider->fetchTasks(new ProviderCredential('pk_test_token', 'conn-1'), '900200', new DateTimeImmutable('@1793000000'), null);

        self::assertTrue($page->lastPage);
        self::assertNull($page->nextCursor);
        self::assertCount(3, $page->tasks);
        $query = $this->requests[0]['query'];
        self::assertSame(['0', 'true', 'true', 'true', 'updated', 'true', '1793000000000'], [(string) $query['page'], $query['subtasks'], $query['include_closed'], $query['include_markdown_description'], $query['order_by'], $query['reverse'], $query['date_updated_gt']]);

        $design = $page->tasks[0];
        self::assertSame('86a1x1', $design->externalId());
        self::assertSame('to do', $design->task->status);
        self::assertSame(TaskPriority::High, $design->task->priority);
        self::assertSame(['user1@example.test'], $design->task->assigneeEmails);
        self::assertSame(60, $design->task->estimatedMinutes);
        self::assertSame(['ux'], $design->task->tags);
        self::assertSame('2026-11-01', $design->task->startAt?->format('Y-m-d'));
        self::assertSame(1793100000, $design->remoteUpdatedAt->getTimestamp());
        self::assertStringContainsString('**board**', (string) $design->task->description);
        // Audit EG-L3: raw HTML is escaped (the text stays), an unsafe link loses its target.
        self::assertDoesNotMatchRegularExpression('/(?<!\\\\)<script/', (string) $design->task->description);
        self::assertDoesNotMatchRegularExpression('/\]\(\s*javascript:/i', (string) $design->task->description);
        self::assertStringContainsString('[spec](https://example.test/spec)', (string) $design->task->description);
        self::assertSame(['https://t000.p.clickup-attachments.com/fixture/1/shot.png'], $design->inlineImageUrls);
        self::assertSame('att-1', $design->attachments[0]->id);
        self::assertSame(1234, $design->attachments[0]->size);
        self::assertSame('application/pdf', $design->attachments[0]->mime);
        self::assertSame('- **Story points**: 5', $design->customFieldsText);
        self::assertSame([['86a1x1', TaskDependencyType::BlockedBy]], $page->tasks[1]->task->dependsOn);
        self::assertSame('86a1x2', $page->tasks[2]->task->parentExternalId);
    }

    public function testPagingCommentsAndExistingIds(): void
    {
        $tasks = [];
        for ($i = 0; $i < 100; ++$i) {
            $tasks[] = ['id' => 't' . $i, 'name' => 'Task ' . $i, 'date_updated' => '1793000000000', 'archived' => $i === 5];
        }
        $comments = [];
        for ($i = 0; $i < 25; ++$i) {
            $comments[] = ['id' => 'c' . $i, 'comment_text' => 'Comment ' . $i, 'user' => ['username' => 'User 2', 'email' => 'user2@example.test'], 'date' => (string) (1793000000000 - $i)];
        }
        $provider = $this->provider([
            'list/L/task?page=0'   => new MockResponse((string) json_encode(['tasks' => $tasks])),
            'list/L/task?page=1'   => new MockResponse((string) json_encode(['tasks' => [['id' => 'last', 'name' => 'Last', 'date_updated' => '1793000000000']], 'last_page' => true])),
            'task/86a1x1/comment?' => 'task_86a1x1_comment.json',
            'task/T/comment?'      => new MockResponse((string) json_encode(['comments' => $comments])),
            'task/T/comment?start' => new MockResponse((string) json_encode(['comments' => [['id' => 'old', 'comment_text' => 'Oldest', 'date' => '1792000000000']]])),
        ]);
        $credential = new ProviderCredential('pk_test_token', 'conn-1');

        $first = $provider->fetchTasks($credential, 'L', null, null);
        self::assertFalse($first->lastPage);
        self::assertSame('1', $first->nextCursor);
        self::assertTrue($provider->fetchTasks($credential, 'L', null, '1')->lastPage);
        $ids = iterator_to_array($provider->existingTaskIds($credential, 'L'), false);
        self::assertCount(100, $ids, '99 + last, without the archived one');
        self::assertNotContains('t5', $ids);

        $fixture = $provider->fetchComments($credential, '86a1x1');
        self::assertCount(2, $fixture);
        self::assertSame('90010002', $fixture[0]->id, 'oldest first');
        self::assertSame('Plain text comment', $fixture[0]->body);
        self::assertSame('Looks **good** see [spec](https://example.test/spec)@User 1![pic.png](https://t000.p.clickup-attachments.com/fixture/3/pic.png)', $fixture[1]->body);
        self::assertSame(['https://t000.p.clickup-attachments.com/fixture/3/pic.png'], $fixture[1]->inlineImageUrls);
        self::assertSame('user2@example.test', $fixture[1]->authorEmail);

        $paged = $provider->fetchComments($credential, 'T');
        self::assertCount(26, $paged);
        self::assertSame('Oldest', $paged[0]->body);

        // A provider that never ends its id pages: refused, a partial scan never looks complete.
        $endless = $this->provider(['list/L/task' => new MockResponse((string) json_encode(['tasks' => $tasks]))]);
        try {
            iterator_to_array($endless->existingTaskIds($credential, 'L'), false);
            self::fail('endless paging');
        } catch (ProviderException $exception) {
            self::assertSame(ProviderErrorCode::ResponseTooLarge, $exception->errorCode);
        }
    }

    /**
     * Unconfirmed against the live API (owner decision 2026-11-04): without `last_page` the paging goes on until an
     * empty page, missing attachment fields are tolerated, and an unexpected shape is logged with its keys only.
     */
    public function testUnconfirmedShapesAreHandledDefensively(): void
    {
        $logger = new RecordingLogger();
        $routes = [
            'list/L/task?page=0' => new MockResponse((string) json_encode(['tasks' => [['id' => 'a1', 'name' => 'Short page', 'secret_field' => 'VALUE_NOT_LOGGED', 'attachments' => [['id' => 'f1', 'url' => 'https://t000.p.clickup-attachments.com/f1'], 'junk']]]])),
            'list/L/task?page=1' => new MockResponse((string) json_encode(['tasks' => []])),
            'list/L/task?page=2' => new MockResponse((string) json_encode(['unexpected' => 'VALUE_NOT_LOGGED', "\e[31mred" . str_repeat('k', 5000) => 1])),
        ];
        $provider   = $this->provider($routes, logger: $logger);
        $credential = new ProviderCredential('pk_test_token', 'conn-1');

        $first = $provider->fetchTasks($credential, 'L', null, null);
        self::assertFalse($first->lastPage, 'a short page without last_page is not trusted to be the last');
        self::assertCount(1, $first->tasks);
        self::assertSame('file', $first->tasks[0]->attachments[0]->name, 'missing attachment fields are tolerated');
        self::assertNull($first->tasks[0]->attachments[0]->size);
        self::assertTrue($provider->fetchTasks($credential, 'L', null, '1')->lastPage, 'an empty page ends the list');
        self::assertTrue($provider->fetchTasks($credential, 'L', null, '2')->lastPage);
        self::assertSame(['a1'], iterator_to_array($provider->existingTaskIds($credential, 'L'), false));

        self::assertNotSame([], $logger->records);
        foreach ($logger->records as $record) {
            self::assertSame('debug', $record['level']);
            self::assertStringNotContainsString('VALUE_NOT_LOGGED', (string) json_encode($record));
            // Audit EG-I12: keys without control characters, 64 bytes at most.
            foreach ($record['context']['keys'] as $key) {
                self::assertLessThanOrEqual(64, strlen($key));
                self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $key);
            }
        }
        self::assertContains('[31mred' . str_repeat('k', 57), array_merge(...array_map(static fn (array $r): array => $r['context']['keys'], $logger->records)));
    }

    /** Audit EG-L2: comment pages that do not move, or rows without a valid id, end the paging. */
    public function testCommentPagingAlwaysEnds(): void
    {
        $same = [];
        for ($i = 0; $i < 25; ++$i) {
            $same[] = ['id' => 'c' . $i, 'comment_text' => 'C' . $i, 'date' => '1793000000000'];
        }
        $provider = $this->provider([
            'task/T/comment?'      => new MockResponse((string) json_encode(['comments' => $same])),
            'task/T/comment?start' => new MockResponse((string) json_encode(['comments' => $same])),
        ]);
        $credential = new ProviderCredential('pk_test_token', 'conn-1');
        self::assertCount(50, $provider->fetchComments($credential, 'T'));
        self::assertCount(2, $this->requests, 'the second page asks for the same place: stop');

        $invalid = [];
        for ($i = 0; $i < 25; ++$i) {
            $invalid[] = ['id' => 'bad id ' . $i, 'comment_text' => 'X'];
        }
        $provider = $this->provider(['task/T/comment?' => new MockResponse((string) json_encode(['comments' => $invalid]))]);
        self::assertSame([], $provider->fetchComments($credential, 'T'));
        self::assertCount(1, $this->requests, 'no valid id to continue from');

        // A moving cursor still stops at ceil(max / 25) + 1 pages.
        $page   = 0;
        $moving = new MockHttpClient(static function () use (&$page): MockResponse {
            $rows = [];
            for ($i = 0; $i < 25; ++$i) {
                $rows[] = ['id' => 'p' . $page . 'c' . $i, 'comment_text' => ' ', 'date' => '1793000000000'];
            }
            ++$page;

            return new MockResponse((string) json_encode(['comments' => $rows]));
        });
        $capped = new ClickUpTaskProvider(new ProviderHttpClient($moving, new RecordingSleeper(), new MockClock()), maxComments: 50);
        self::assertCount(50, $capped->fetchComments($credential, 'T'));
        self::assertSame(2, $page, 'raw rows count: 50 rows read, no third page');
    }

    public function testCommentCapAndOddPayloads(): void
    {
        $provider = $this->provider([
            'task/T/comment?' => new MockResponse((string) json_encode(['comments' => [['id' => 'a', 'comment_text' => 'A'], ['id' => 'b', 'comment_text' => 'B'], ['comment_text' => 'no id'], 'junk', ['id' => 'c', 'comment' => [['text' => ' '], 'junk', ['text' => 'x', 'attributes' => ['code' => true, 'italic' => true]], ['type' => 'image', 'image' => []]]]]])),
            'list/L/task'     => new MockResponse((string) json_encode(['tasks' => [['id' => 'bad id!', 'name' => 'Bad'], ['id' => 'ok', 'name' => '', 'date_created' => '1793000000000'], ['id' => 'ok2', 'name' => 'Ok', 'description' => 'Plain', 'custom_fields' => [['name' => 'Flag', 'value' => true], ['name' => 'List', 'value' => ['a' => 1]], ['name' => 'Off', 'value' => false]], 'attachments' => [['id' => 'x'], ['id' => 'y', 'url' => 'https://t000.p.clickup-attachments.com/y']], 'date_updated' => 'garbage']], 'last_page' => true])),
        ], maxComments: 2);
        $credential = new ProviderCredential('pk_test_token', 'conn-1');

        self::assertCount(2, $provider->fetchComments($credential, 'T'));
        $page = $provider->fetchTasks($credential, 'L', null, 'garbage');
        self::assertCount(1, $page->tasks);
        self::assertSame("- **Flag**: yes\n- **List**: {\"a\":1}\n- **Off**: no", $page->tasks[0]->customFieldsText);
        self::assertSame('file', $page->tasks[0]->attachments[0]->name);
        self::assertSame(0, $page->tasks[0]->remoteUpdatedAt->getTimestamp());
        self::assertSame('Plain', $page->tasks[0]->task->description);

        $this->expectException(ProviderException::class);
        $provider->fetchTasks($credential, '../x', null, null);
    }

    public function testIdentityAndErrors(): void
    {
        $provider = $this->provider(['list/L/task' => new MockResponse('', ['http_code' => 401]), 'list/L' => new MockResponse('', ['http_code' => 403])]);
        self::assertSame('clickup', $provider->id());
        self::assertSame('task_board.sync.provider.clickup', $provider->labelKey());
        self::assertSame('clickup', $provider->importScope(new ProviderCredential('x', 'c')));
        self::assertTrue($provider->capabilities()->incremental);
        self::assertSame(ClickUpTaskProvider::DEFAULT_ATTACHMENT_HOSTS, $provider->capabilities()->attachmentHosts);

        try {
            iterator_to_array($provider->existingTaskIds(new ProviderCredential('pk_wrong', 'c'), 'L'));
            self::fail('auth');
        } catch (ProviderException $exception) {
            self::assertSame(ProviderErrorCode::AuthFailed, $exception->errorCode);
        }
        $this->expectException(ProviderException::class);
        $provider->findContainer(new ProviderCredential('pk_wrong', 'c'), 'L');
    }

    /**
     * Since 1.27.0 (TB-SYNC-3): threads (replies after their comment, oldest first, within the comment cap), comment
     * files, assigned comments and their resolved state, the edit date, and typed custom fields on the task.
     */
    public function testThreadsActionItemsCommentFilesAndTypedFields(): void
    {
        $file     = ['id' => 'att1', 'title' => 'spec.pdf', 'url' => 'https://t000.p.clickup-attachments.com/att1/spec.pdf', 'size' => 10, 'mimetype' => 'application/pdf'];
        $provider = $this->provider([
            'task/T/comment?' => new MockResponse((string) json_encode(['comments' => [
                ['id' => 'c2', 'comment_text' => 'Second', 'date' => '1790000002000', 'reply_count' => 0],
                ['id' => 'c1', 'comment' => [['text' => 'See '], ['type' => 'attachment', 'text' => 'spec.pdf', 'attachment' => $file], ['type' => 'attachment']], 'date' => '1790000001000', 'reply_count' => '2', 'assignee' => ['id' => 7, 'username' => 'Ana'], 'resolved' => true, 'date_updated' => '1790000009000', 'user' => ['id' => 5, 'username' => 'Bob']],
            ]])),
            'comment/c1/reply' => new MockResponse((string) json_encode(['comments' => [
                ['id' => 'r2', 'comment_text' => 'Later reply', 'date' => '1790000004000'],
                ['id' => 'r1', 'comment_text' => 'First reply', 'date' => '1790000003000', 'resolved' => true],
                ['comment_text' => 'no id'],
            ]])),
            'list/L/task' => new MockResponse((string) json_encode(['tasks' => [['id' => 'ok', 'name' => 'Ok', 'custom_fields' => [['id' => 'f1', 'name' => 'Budget', 'type' => 'currency', 'type_config' => ['currency_type' => 'USD'], 'value' => '10']]]], 'last_page' => true])),
        ]);
        $credential = new ProviderCredential('pk_test_token', 'conn-1');

        $comments = [...$provider->fetchComments($credential, 'T')];
        self::assertSame(['c1', 'r1', 'r2', 'c2'], array_map(static fn (RemoteComment $c): string => $c->id, $comments));
        self::assertSame([null, 'c1', 'c1', null], array_map(static fn (RemoteComment $c): ?string => $c->parentId, $comments));
        self::assertSame('See', trim($comments[0]->body), 'the attachment segment is a file, not text');
        self::assertCount(1, $comments[0]->attachments);
        self::assertSame('spec.pdf', $comments[0]->attachments[0]->name);
        self::assertSame('7', $comments[0]->assignee?->providerUserId);
        self::assertTrue($comments[0]->resolved);
        self::assertSame(1790000009, $comments[0]->updatedAt?->getTimestamp());
        self::assertNull($comments[1]->resolved, 'resolved without an assignee is no action item');
        self::assertSame('https://api.clickup.com/api/v2/comment/c1/reply', $this->requests[1]['url']);
        $task = $provider->fetchTasks($credential, 'L', null, null)->tasks[0];
        self::assertSame('10 USD', $task->customFields[0]->text);

        // The cap counts the replies: with two comments at most, no reply is read.
        $capped = $this->provider(['task/T/comment?' => new MockResponse((string) json_encode(['comments' => [['id' => 'c1', 'comment_text' => 'A', 'reply_count' => 3], ['id' => 'c0', 'comment_text' => 'B']]]))], maxComments: 2);
        self::assertCount(2, [...$capped->fetchComments($credential, 'T')]);
        self::assertCount(1, $this->requests);
        $one = $this->provider([
            'task/T/comment?'  => new MockResponse((string) json_encode(['comments' => [['id' => 'c1', 'comment_text' => 'A', 'reply_count' => 3]]])),
            'comment/c1/reply' => new MockResponse((string) json_encode(['comments' => [['id' => 'r1', 'comment_text' => 'x'], ['id' => 'r2', 'comment_text' => 'y']]])),
        ], maxComments: 2);
        self::assertSame(['c1', 'r1'], array_map(static fn (RemoteComment $c): string => $c->id, [...$one->fetchComments($credential, 'T')]));

    }

    /**
     * Since 1.27.0 (TB-SYNC-3): custom id, points, done / closed dates, time flags, location, estimates, recurrence,
     * the keys with a value, linked tasks, the declared coverage, the time in status and the archived pass.
     */
    public function testDetailsLinksHistoryAndTheArchivedPass(): void
    {
        $row = [
            'id'                     => 'ok', 'name' => 'Ok', 'custom_id' => 'DEV-123', 'points' => 2.5, 'date_done' => '1790000000000', 'date_closed' => '1790000100000',
            'start_date_time'        => true, 'due_date_time' => false, 'folder' => ['id' => 'f', 'name' => 'Folder', 'hidden' => false], 'list' => ['id' => 'l', 'name' => 'List'],
            'time_estimates_by_user' => [['user' => ['id' => 7, 'username' => 'Ana'], 'time_estimate' => 5_400_000], ['user' => ['id' => 'bad id!'], 'time_estimate' => 1], 'junk'],
            'recurrence'             => 'Every week', 'linked_tasks' => [['task_id' => 'ok', 'link_id' => 'other'], ['task_id' => 'third', 'link_id' => 'ok'], ['task_id' => 'x', 'link_id' => 'y'], 'junk'],
            'Bad-Key'                => 1, 'empty' => '', 'mystery' => ['a'],
        ];
        $odd      = ['id' => 'odd', 'name' => 'Odd', 'custom_id' => 'not valid!', 'points' => 'many', 'folder' => ['id' => 'f', 'name' => 'Hidden', 'hidden' => true], 'recurrence' => ['x']];
        $provider = $this->provider([
            'list/L/task?page=0'       => new MockResponse((string) json_encode(['tasks' => [$row, $odd, ['id' => 'inf', 'name' => 'Inf', 'points' => '1e999']], 'last_page' => true])),
            'task/dup/time_in_status'  => new MockResponse((string) json_encode(['current_status' => ['status' => 'open'], 'status_history' => [['status' => 'open', 'total_time' => ['by_minute' => 9]], ['status' => 'done', 'total_time' => ['by_minute' => 2]]]])),
            'list/L/task?page=1'       => new MockResponse((string) json_encode(['tasks' => [], 'last_page' => true])),
            'task/ok/time_in_status'   => new MockResponse((string) json_encode(['current_status' => ['status' => 'done', 'total_time' => ['by_minute' => 3, 'since' => '1790000000000']], 'status_history' => [['status' => 'to do', 'total_time' => ['by_minute' => 60, 'since' => '1789000000000']], ['status' => 'done', 'total_time' => []], 'junk', ['status' => '']]])),
            'task/new/time_in_status'  => new MockResponse((string) json_encode(['current_status' => ['status' => 'open'], 'status_history' => 'junk'])),
            'task/none/time_in_status' => new MockResponse((string) json_encode(['status_history' => []])),
        ], includeArchived: true);
        $credential = new ProviderCredential('pk_test_token', 'conn-1');

        $page = $provider->fetchTasks($credential, 'L', null, null);
        self::assertSame(['a0', false], [$page->nextCursor, $page->lastPage], 'the archived pass follows the active pages');
        $details = $page->tasks[0]->details;
        self::assertSame(['DEV-123', '2.5', 1790000000, 1790000100, true, false, ['Folder', 'List'], 'Every week'], [$details?->customId, $details?->points, $details?->doneAt?->getTimestamp(), $details?->closedAt?->getTimestamp(), $details?->startHasTime, $details?->dueHasTime, $details?->path, $details?->recurrence]);
        self::assertSame(['7', 90], [$details?->estimates[0]['person']->providerUserId, $details?->estimates[0]['minutes']]);
        self::assertArrayNotHasKey('estimates', (array) $details?->toArray(), 'no person name in the stored JSON');
        self::assertTrue($page->passEnded, 'an empty last active page is not a stalled list');
        self::assertNull($page->tasks[2]->details?->points, 'not a finite number');
        self::assertSame(['other', 'third'], array_map(static fn (array $d): string => $d[0], $page->tasks[0]->task->dependsOn));
        self::assertNotContains('Bad-Key', $page->tasks[0]->presentKeys);
        self::assertNotContains('empty', $page->tasks[0]->presentKeys);
        self::assertContains('mystery', $page->tasks[0]->presentKeys);
        self::assertSame([null, null, [], null], [$page->tasks[1]->details?->customId, $page->tasks[1]->details?->points, $page->tasks[1]->details?->path, $page->tasks[1]->details?->recurrence]);
        self::assertSame([], (new RemoteTaskDetails())->toArray());

        $archived = $provider->fetchTasks($credential, 'L', null, 'a0');
        self::assertSame('true', $this->requests[count($this->requests) - 1]['query']['archived']);
        self::assertSame([null, true, false], [$archived->nextCursor, $archived->lastPage, $archived->passEnded]);

        self::assertSame('imported', $provider->fieldCoverage()['custom_id']);
        $history = $provider->fetchStatusHistory($credential, 'ok');
        self::assertSame([['to do', 60, false], ['done', 3, true]], array_map(static fn (RemoteStatusTime $s): array => [$s->status, $s->minutes, $s->current], (array) $history));
        self::assertSame(1790000000, $history[1]->since?->getTimestamp());
        self::assertSame([['open', 0, true]], array_map(static fn (RemoteStatusTime $s): array => [$s->status, $s->minutes, $s->current], (array) $provider->fetchStatusHistory($credential, 'new')));
        self::assertSame([['done', 2, false], ['open', 0, true]], array_map(static fn (RemoteStatusTime $s): array => [$s->status, $s->minutes, $s->current], (array) $provider->fetchStatusHistory($credential, 'dup')), 'the current status goes last, once');
        self::assertSame([], $provider->fetchStatusHistory($credential, 'none'));
        self::assertNull($provider->fetchStatusHistory($credential, 'missing'), 'not served (404)');
    }

    /** Since 1.27.0 (audit GW-L6, GW-I9, GW-L7): reply requests are budgeted, a 404 has no thread, dates are bounded. */
    public function testReplyBudgetGoneThreadsAndBoundedDates(): void
    {
        $comments = array_map(static fn (int $i): array => ['id' => 'c' . $i, 'comment_text' => 'Body ' . $i, 'date' => '179000000' . $i . '000', 'reply_count' => 1], [1, 2, 3]);
        $provider = $this->provider([
            'task/T/comment'     => new MockResponse((string) json_encode(['comments' => $comments])),
            'comment/c1/reply'   => new MockResponse('{}', ['http_code' => 404]),
            'comment/c2/reply'   => new MockResponse((string) json_encode(['comments' => []])),
            'list/L/task?page=0' => new MockResponse((string) json_encode(['tasks' => [['id' => 'far', 'name' => 'Far', 'date_done' => '999999999999999', 'date_closed' => '100000'], ['id' => 'ok', 'name' => 'Ok', 'attachments' => []]], 'last_page' => true])),
        ]);
        $credential = new ProviderCredential('pk_test_token', 'conn-1');
        $budget     = new ReplyBudget(2);
        self::assertCount(3, [...$provider->fetchCommentsWithin($credential, 'T', $budget)]);
        self::assertSame([2, 1], [$budget->used, $budget->skipped]);

        $page = $provider->fetchTasks($credential, 'L', null, null);
        self::assertSame([null, null], [$page->tasks[0]->details?->doneAt, $page->tasks[0]->details?->closedAt]);
        self::assertSame([false, true], [$page->tasks[0]->attachmentsKnown, $page->tasks[1]->attachmentsKnown]);
    }

    /**
     * @param array<string, MockResponse|string> $routes
     */
    private function provider(array $routes, int $maxComments = 500, ?RecordingLogger $logger = null, bool $includeArchived = false): ClickUpTaskProvider
    {
        $this->routes   = $routes;
        $this->requests = [];
        $client         = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $parts = parse_url($url);
            $path  = substr((string) ($parts['path'] ?? ''), strlen('/api/v2/'));
            parse_str((string) ($parts['query'] ?? ''), $query);
            $headers = [];
            foreach ($options['normalized_headers'] ?? [] as $name => $values) {
                $headers[$name] = substr((string) $values[0], strlen($name) + 2);
            }
            $this->requests[] = ['url' => $url, 'query' => $query, 'headers' => $headers];
            $candidates       = [
                $path . '?' . (isset($query['start']) ? 'start' : ''),
                $path . '?page=' . ($query['page'] ?? ''),
                $path,
            ];
            foreach ($candidates as $key) {
                if (isset($this->routes[$key])) {
                    $route = $this->routes[$key];

                    return $route instanceof MockResponse ? clone $route : new MockResponse((string) file_get_contents(self::FIXTURES . $route));
                }
            }

            return new MockResponse('{"err": "not found"}', ['http_code' => 404]);
        });

        return new ClickUpTaskProvider(
            new ProviderHttpClient($client, new RecordingSleeper(), new MockClock()),
            maxComments: $maxComments,
            logger: $logger ?? new RecordingLogger(),
            includeArchived: $includeArchived,
        );
    }
}
