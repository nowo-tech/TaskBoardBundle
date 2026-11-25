<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Exception\RateLimitedException;
use Nowo\TaskBoardBundle\Sync\Http\ProviderHttpClient;
use Nowo\TaskBoardBundle\Sync\Http\ProviderRateBudget;
use Nowo\TaskBoardBundle\Sync\Http\SleeperInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_keys;

/**
 * Since 3.2.0 (CU-2, design §13): the per-token budget shared by every process — one-minute windows below the last
 * X-RateLimit-Limit less the margin, keyed by an HMAC of the reference (never the token), fail open.
 */
final class ProviderRateBudgetTest extends TestCase
{
    public function testWindowsLimitAndMargin(): void
    {
        $pool   = new ArrayAdapter();
        $clock  = new MockClock(new DateTimeImmutable('@1790000010'));
        $budget = new ProviderRateBudget($pool, 'secret', $clock, defaultLimit: 10, margin: 0.2);

        self::assertSame(10, $budget->limit('clickup:conn-1'));
        self::assertSame(8, $budget->allowed('clickup:conn-1'));
        for ($i = 0; $i < 8; ++$i) {
            self::assertSame(0, $budget->reserve('clickup:conn-1'));
        }
        self::assertSame(30, $budget->reserve('clickup:conn-1'), 'until the next minute');
        self::assertSame(0, $budget->reserve('clickup:conn-2'), 'another token has its own budget');

        $budget->observeLimit('clickup:conn-1', 100);
        $budget->observeLimit('clickup:conn-1', 100);
        $budget->observeLimit('clickup:conn-1', 0);
        self::assertSame(100, $budget->limit('clickup:conn-1'));
        self::assertSame(80, $budget->allowed('clickup:conn-1'));

        $clock->sleep(60);
        self::assertSame(0, $budget->reserve('clickup:conn-1'));
        foreach (array_keys($pool->getValues()) as $key) {
            self::assertStringNotContainsString('conn-1', $key);
        }
        self::assertSame(1, (new ProviderRateBudget($pool, 's', $clock, defaultLimit: 1, margin: 0.9))->allowed('x'), 'at least one');
    }

    public function testABrokenPoolLetsTheRequestGo(): void
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new RuntimeException('down'));
        $budget = new ProviderRateBudget($pool, 'secret');

        self::assertSame(0, $budget->reserve('k'));
        $budget->observeLimit('k', 50);
        self::assertSame(100, $budget->limit('k'));
    }

    public function testTheClientTakesFromTheBudgetAndLearnsTheLimit(): void
    {
        $pool    = new ArrayAdapter();
        $clock   = new MockClock(new DateTimeImmutable('@1790000050'));
        $budget  = new ProviderRateBudget($pool, 'secret', $clock, defaultLimit: 1, margin: 0.0);
        $sleeper = new RecordingSleeper();
        $client  = new ProviderHttpClient(new MockHttpClient([
            new MockResponse('[]', ['response_headers' => ['x-ratelimit-limit' => '3']]),
            new MockResponse('[]'),
            new MockResponse('[]'),
        ]), $sleeper, $clock, maxInlineWait: 30, budget: $budget);

        $client->getJson('https://api.test/x', [], [], 'clickup:conn-1');
        self::assertSame(3, $budget->limit('clickup:conn-1'));
        $client->getJson('https://api.test/x', [], [], 'clickup:conn-1');
        $client->getJson('https://api.test/x', [], []);
        self::assertSame([], $sleeper->waits);

        $budget->reserve('clickup:conn-1');
        try {
            (new ProviderHttpClient(new MockHttpClient([]), $sleeper, $clock, maxInlineWait: 5, budget: $budget))->getJson('https://api.test/x', [], [], 'clickup:conn-1');
            self::fail('the budget is empty for 50 s');
        } catch (RateLimitedException $exception) {
            self::assertSame(50, $exception->retryAfterSeconds);
        }
    }

    public function testAShortWaitIsSleptInline(): void
    {
        $clock   = new MockClock(new DateTimeImmutable('@1790000095'));
        $budget  = new ProviderRateBudget(new ArrayAdapter(), 'secret', $clock, defaultLimit: 1, margin: 0.0);
        $sleeper = new class($clock) implements SleeperInterface {
            /** @var list<int> */
            public array $waits = [];

            public function __construct(private readonly MockClock $clock)
            {
            }

            public function sleep(int $seconds): void
            {
                $this->waits[] = $seconds;
                $this->clock->sleep($seconds);
            }
        };
        $budget->reserve('k');
        (new ProviderHttpClient(new MockHttpClient([new MockResponse('[]')]), $sleeper, $clock, maxInlineWait: 30, budget: $budget))->getJson('https://api.test/x', [], [], 'k');
        self::assertSame([5], $sleeper->waits);
    }
}
