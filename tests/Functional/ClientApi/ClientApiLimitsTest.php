<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

/**
 * Since 3.4.0: the Task API's limits through the client API — `search.max_matches` and `max_subtask_depth`.
 */
final class ClientApiLimitsTest extends ClientApiTestCase
{
    protected function setUp(): void
    {
        self::$hostConfig = ['search' => ['max_matches' => 1], 'max_subtask_depth' => 1];
        parent::setUp();
    }

    public function testLimits(): void
    {
        $board = $this->board('Roadmap', tenantKey: 'tenant-a');
        $top   = $this->task($board, 'Widget one');
        $this->task($board, 'Widget two');
        $this->call('GET', '/boards/' . $board->getId() . '/tasks?q=widget');
        $this->assertError(400, 'search_too_broad');

        $sub = $this->task($board, 'Sub', 0, $top);
        $this->call('POST', '/tasks', ['boardId' => $board->getId(), 'title' => 'Too deep', 'parentId' => $sub->getId()], ['HTTP_IDEMPOTENCY_KEY' => 'deep']);
        $this->assertError(409, 'parent_refused');
        self::assertSame('too_deep', $this->json()['reason']);
    }
}
