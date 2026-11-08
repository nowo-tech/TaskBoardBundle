<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Api;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Event\TaskApiWriteEvent;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Since 2.4.0 (spec 219): `channel` is an optional trailing argument; a host built for 2.2 keeps compiling and gets `api`.
 */
final class TaskApiPrincipalChannelTest extends TestCase
{
    public function testTheChannelDefaultsToApiAndReachesTheWriteEvent(): void
    {
        $user = new TestUser('1', 'owner@example.com');

        $default = new TaskApiPrincipal($user, TaskApiLevel::Full, TaskApiWhere::everything(), 'id', 'name');
        self::assertSame('api', $default->channel);
        self::assertSame(TaskApiPrincipal::CHANNEL_API, (new TaskApiWriteEvent($default, 'task.update', null, null, null))->channel);

        $mcp = new TaskApiPrincipal($user, TaskApiLevel::Full, TaskApiWhere::everything(), 'id', 'name', TaskApiPrincipal::CHANNEL_MCP);
        self::assertSame('mcp', $mcp->channel);
        self::assertSame('mcp', (new TaskApiWriteEvent($mcp, 'task.update', null, null, null))->channel);
        self::assertSame('host_cli', (new TaskApiPrincipal($user, TaskApiLevel::Full, TaskApiWhere::everything(), 'id', 'name', 'host_cli'))->channel);
    }

    /**
     * Audit II-I4: a short lowercase label only.
     */
    #[DataProvider('invalidChannels')]
    public function testAnythingElseIsRefused(string $channel): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TaskApiPrincipal(new TestUser('1', 'owner@example.com'), TaskApiLevel::Full, TaskApiWhere::everything(), 'id', 'name', $channel);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidChannels(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['MCP'];
        yield 'new line' => ["mcp\nforged"];
        yield 'space' => ['m cp'];
        yield 'leading digit' => ['1mcp'];
        yield 'too long' => [str_repeat('a', 33)];
    }
}
