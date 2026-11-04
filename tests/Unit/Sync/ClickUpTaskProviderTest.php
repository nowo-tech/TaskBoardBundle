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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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
        self::assertStringNotContainsString('<script>', (string) $design->task->description);
        self::assertStringNotContainsString('javascript:', (string) $design->task->description);
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
     * @param array<string, MockResponse|string> $routes
     */
    private function provider(array $routes, int $maxComments = 500): ClickUpTaskProvider
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
        );
    }
}
