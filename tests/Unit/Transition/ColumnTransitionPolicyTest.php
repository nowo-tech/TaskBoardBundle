<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Transition;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Transition\AllowAllColumnTransitionPolicy;
use Nowo\TaskBoardBundle\Transition\HumansOnlyColumnTransitionPolicy;
use Nowo\TaskBoardBundle\ValueObject\BoardColumnSnapshot;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionDecision;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ColumnTransitionPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: bool, 1: string, 2: string, 3: bool}>
     */
    public static function cases(): iterable
    {
        yield 'api into humans only' => [true, ColumnTransitionVia::API, ColumnTransitionVia::OP_MOVE, false];
        yield 'mcp into humans only' => [true, ColumnTransitionVia::MCP, ColumnTransitionVia::OP_BULK, false];
        yield 'mcp creates there' => [true, ColumnTransitionVia::MCP, ColumnTransitionVia::OP_CREATE, false];
        yield 'web into humans only' => [true, ColumnTransitionVia::WEB, ColumnTransitionVia::OP_MOVE, true];
        yield 'a host label' => [true, 'cron', ColumnTransitionVia::OP_MOVE, true];
        yield 'checklist item by the api' => [true, ColumnTransitionVia::API, ColumnTransitionVia::OP_CHECKLIST, false];
        yield 'api into a plain column' => [false, ColumnTransitionVia::API, ColumnTransitionVia::OP_MOVE, true];
        // Audit PC-L2: a retag of a task already in a humans-only column does not enter it.
        yield 'mcp retags a task in a humans only column' => [true, ColumnTransitionVia::MCP, ColumnTransitionVia::OP_RETAG, true];
        // Audit PC-M1: a column deletion moving tasks into a humans-only column is a machine move like any other.
        yield 'mcp deletes a column into a humans only one' => [true, ColumnTransitionVia::MCP, ColumnTransitionVia::OP_COLUMN_DELETE, false];
    }

    #[DataProvider('cases')]
    public function testHumansOnly(bool $humansOnly, string $channel, string $operation, bool $allowed): void
    {
        $decision = (new HumansOnlyColumnTransitionPolicy())->decide($this->transition($humansOnly, new ColumnTransitionVia($channel, $operation)));
        self::assertSame($allowed, $decision->allowed);
        self::assertSame($allowed ? '' : 'STATUS_HUMANS_ONLY', $decision->code);
        self::assertTrue((new AllowAllColumnTransitionPolicy())->decide($this->transition($humansOnly, ColumnTransitionVia::web()))->allowed);
    }

    public function testChecklistItemsMayBeAllowedByOption(): void
    {
        $lenient = new HumansOnlyColumnTransitionPolicy(allowChecklist: true);
        self::assertTrue($lenient->decide(self::transition(true, new ColumnTransitionVia(ColumnTransitionVia::MCP, ColumnTransitionVia::OP_CHECKLIST)))->allowed);
        self::assertFalse($lenient->decide(self::transition(true, new ColumnTransitionVia(ColumnTransitionVia::MCP, ColumnTransitionVia::OP_MOVE)))->allowed);
    }

    public function testADecisionKeepsAValidCodeOnly(): void
    {
        self::assertSame(['WIP_LIMIT', 'One per repo'], [ColumnTransitionDecision::refuse('WIP_LIMIT', 'One per repo')->code, ColumnTransitionDecision::refuse('WIP_LIMIT', 'One per repo')->message]);
        self::assertSame('STATUS_REFUSED', ColumnTransitionDecision::refuse('wip limit')->code);
        self::assertSame('STATUS_REFUSED', ColumnTransitionDecision::refuse()->code);
        self::assertFalse(ColumnTransitionDecision::refuse()->allowed);
        self::assertTrue(ColumnTransitionDecision::allow()->allowed);
    }

    public function testAViaIsTwoShortLowercaseLabels(): void
    {
        self::assertSame(['web', 'create'], [ColumnTransitionVia::web('create')->channel, ColumnTransitionVia::web('create')->operation]);
        $this->expectException(InvalidArgumentException::class);
        new ColumnTransitionVia('Web');
    }

    public function testAnOperationIsALabelToo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ColumnTransitionVia('api', 'drop table');
    }

    public function testATransitionWithoutATaskIsACreation(): void
    {
        $transition = $this->transition(false, ColumnTransitionVia::web());
        self::assertTrue($transition->isCreation());
        self::assertSame('web', $transition->channel());
    }

    private function transition(bool $humansOnly, ColumnTransitionVia $via): ColumnTransition
    {
        $board = new TaskBoardSnapshot('b1', 'roadmap', 'Roadmap', 'tenant-a', null, null, false, new DateTimeImmutable());

        return new ColumnTransition($board, null, null, new BoardColumnSnapshot('c2', 'b1', 'Done', 2, $humansOnly, true), new stdClass(), null, $via, ['repo:core']);
    }
}
