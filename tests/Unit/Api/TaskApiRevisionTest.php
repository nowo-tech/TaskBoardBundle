<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Api;

use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiRevision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaskApiRevisionTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string|null, 1: int|null}>
     */
    public static function headers(): iterable
    {
        yield 'none' => [null, null];
        yield 'empty' => ['  ', null];
        yield 'star' => ['*', null];
        yield 'quoted' => ['"7"', 7];
        yield 'bare' => ['12', 12];
        yield 'weak' => ['W/"3"', 3];
    }

    #[DataProvider('headers')]
    public function testIfMatch(?string $header, ?int $revision): void
    {
        self::assertSame($revision, TaskApiRevision::ifMatch($header));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalid(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['"-1"'];
        yield 'text' => ['abc'];
        yield 'too big' => ['2147483648'];
        yield 'list' => ['"1", "2"'];
    }

    #[DataProvider('invalid')]
    public function testAnythingElseIs400(string $header): void
    {
        try {
            TaskApiRevision::ifMatch($header);
            self::fail('No refusal');
        } catch (TaskApiRefusal $refusal) {
            self::assertSame([400, 'INPUT_002'], [$refusal->status, $refusal->ecode]);
        }
    }
}
