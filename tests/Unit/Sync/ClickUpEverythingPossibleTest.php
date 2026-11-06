<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use Nowo\TaskBoardBundle\Sync\Http\ProviderHttpClient;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpTaskProvider;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\ReplyBudget;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function iterator_to_array;
use function json_encode;
use function parse_str;
use function parse_url;
use function strlen;
use function substr;

/**
 * Since 1.28.0 (TB-SYNC-3 addendum). Shapes from ClickUp's API reference; no live workspace was read.
 */
final class ClickUpEverythingPossibleTest extends TestCase
{
    /** @var list<array{path: string, query: array<mixed>}> */
    private array $requests = [];

    /**
     * @param array<string, array<mixed>> $routes
     */
    private function provider(array $routes, bool $multiList = false): ClickUpTaskProvider
    {
        $this->requests = [];
        $client         = new MockHttpClient(function (string $method, string $url) use ($routes): MockResponse {
            $parts = parse_url($url);
            $path  = substr((string) ($parts['path'] ?? ''), strlen('/api/v2/'));
            parse_str((string) ($parts['query'] ?? ''), $query);
            $this->requests[] = ['path' => $path, 'query' => $query];

            return isset($routes[$path]) ? new MockResponse((string) json_encode($routes[$path])) : new MockResponse('{}', ['http_code' => 404]);
        });

        return new ClickUpTaskProvider(new ProviderHttpClient($client, new RecordingSleeper(), new MockClock()), logger: new RecordingLogger(), includeMultiList: $multiList);
    }

    private function credential(): ProviderCredential
    {
        return new ProviderCredential('pk_test_token', 'conn-1');
    }

    public function testTypeLocationsAndTagColoursAreRead(): void
    {
        $provider = $this->provider(['list/L/task' => ['last_page' => true, 'tasks' => [[
            'id'             => 'T1',
            'name'           => 'One',
            'team_id'        => '900',
            'custom_item_id' => 1007,
            'locations'      => [['id' => 'L2', 'name' => 'Other list'], ['id' => 'L2', 'name' => 'Twice'], ['id' => '../bad', 'name' => 'Bad'], 'junk'],
            'tags'           => [
                ['name' => 'urgent', 'tag_fg' => '#FFF', 'tag_bg' => '#ea80fc'],
                ['name' => 'half', 'tag_fg' => 'red;background:url(x)', 'tag_bg' => '#123456'],
                ['name' => 'none', 'tag_fg' => 'red', 'tag_bg' => 'blue'],
            ],
        ]]]]);

        $details = $provider->fetchTasks($this->credential(), 'L', null, null)->tasks[0]->details;

        self::assertSame('1007', $details?->taskTypeId);
        self::assertSame('900', $details->workspaceId);
        self::assertSame([['id' => 'L2', 'name' => 'Twice']], $details->locations);
        self::assertSame(['urgent' => ['fg' => '#FFF', 'bg' => '#ea80fc'], 'half' => ['fg' => null, 'bg' => '#123456']], $details->tagColours);
        self::assertArrayNotHasKey('include_timl', $this->requests[0]['query'], 'off by default');
    }

    public function testNoTypeAndOddValues(): void
    {
        $provider = $this->provider(['list/L/task' => ['last_page' => true, 'tasks' => [
            ['id' => 'T1', 'name' => 'One', 'custom_item_id' => null, 'team_id' => '../x', 'locations' => 'x', 'tags' => 'x'],
            ['id' => 'T2', 'name' => 'Two', 'custom_item_id' => 'bad id!'],
            ['id' => 'T3', 'name' => 'Three', 'custom_item_id' => 'uuid-1'],
        ]]]);
        $tasks = $provider->fetchTasks($this->credential(), 'L', null, null)->tasks;
        self::assertNull($tasks[0]->details?->taskTypeId);
        self::assertNull($tasks[0]->details->workspaceId);
        self::assertSame([], $tasks[0]->details->locations);
        self::assertSame([], $tasks[0]->details->tagColours);
        self::assertNull($tasks[1]->details?->taskTypeId);
        self::assertSame('uuid-1', $tasks[2]->details?->taskTypeId);
    }

    public function testMultiListIsSentOnTheImportAndOnTheDeletionScan(): void
    {
        $provider = $this->provider(['list/L/task' => ['last_page' => true, 'tasks' => [['id' => 'T1', 'name' => 'One']]]], true);
        $provider->fetchTasks($this->credential(), 'L', null, null);
        self::assertSame('true', $this->requests[0]['query']['include_timl'] ?? null);
        self::assertSame(['T1'], iterator_to_array($provider->existingTaskIds($this->credential(), 'L'), false));
        self::assertSame('true', $this->requests[1]['query']['include_timl'] ?? null, 'or a task listed elsewhere would look deleted');
    }

    public function testTheWorkspaceTypes(): void
    {
        $provider = $this->provider(['team/900/custom_item' => ['custom_items' => [
            ['id' => 1007, 'name' => 'Bug', 'name_plural' => 'Bugs'],
            ['id' => 'a-uuid', 'name' => "  Milestone\n"],
            ['id' => '../x', 'name' => 'Bad'],
            ['id' => 5, 'name' => ''],
        ]]]);
        self::assertSame(['1007' => 'Bug', 'a-uuid' => 'Milestone'], $provider->fetchTaskTypes($this->credential(), '900'));
    }

    public function testTheListDescriptionIsSafeMarkdown(): void
    {
        $provider = $this->provider(['list/L' => ['id' => 'L', 'name' => 'List', 'content' => 'About <b>this</b> [x](javascript:y)']]);
        self::assertSame('About \\<b>this\\</b> [x]\\(javascript:y)', $provider->findContainer($this->credential(), 'L')?->description);
        $none = $this->provider(['list/L' => ['id' => 'L', 'name' => 'List', 'content' => '']]);
        self::assertNull($none->findContainer($this->credential(), 'L')?->description);
    }

    public function testWhatTheApiDoesNotGiveIsNeverImported(): void
    {
        $provider = $this->provider([]);
        foreach (['reminders', 'sprints', 'goal_links', 'folder_description', 'space_description'] as $key) {
            self::assertSame('not_exposed', $provider->apiGaps()[$key] ?? null, $key);
        }
        self::assertSame('imported', $provider->fieldCoverage()['custom_item_id']);
        self::assertSame('imported', $provider->fieldCoverage()['locations']);
        self::assertNotContains('imported', $provider->apiGaps());
    }

    /** Open question: does a new reply move `date_updated`? If not, a raised `reply_count` still gets read. */
    public function testAnAlreadyReadVersionStillReadsAThreadThatGrew(): void
    {
        $routes = [
            'task/T/comment' => ['comments' => [
                ['id' => 'c1', 'comment_text' => 'Same', 'reply_count' => '1', 'date' => '1790000000000'],
                ['id' => 'c2', 'comment_text' => 'Grew', 'reply_count' => '2', 'date' => '1790000000000'],
            ]],
            'comment/c2/reply' => ['comments' => [['id' => 'r1', 'comment_text' => 'New', 'date' => '1790000000000']]],
        ];
        $provider = $this->provider($routes);
        $budget   = ReplyBudget::alreadyReadExcept(['c1' => 1, 'c2' => 1], 10);
        $comments = [...$provider->fetchCommentsWithin($this->credential(), 'T', $budget)];
        self::assertSame(['task/T/comment', 'comment/c2/reply'], [$this->requests[0]['path'], $this->requests[1]['path']]);
        self::assertCount(3, $comments);
        self::assertSame(1, $budget->used);

        // Out of budget: nothing is read and nothing is reported as skipped for an already read version.
        $none = ReplyBudget::alreadyReadExcept(['c1' => 1], 0);
        $this->provider($routes)->fetchCommentsWithin($this->credential(), 'T', $none);
        self::assertSame(0, $none->skipped);

        // The 1.27 form, and an adapter that never asks: no request.
        self::assertFalse(ReplyBudget::alreadyRead()->take());
        self::assertFalse(ReplyBudget::alreadyReadExcept(['c' => 0], 5)->take());
    }
}
