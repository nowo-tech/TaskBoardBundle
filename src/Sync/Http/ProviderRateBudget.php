<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Http;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Throwable;

use function hash_hmac;
use function intdiv;
use function is_int;
use function max;

/**
 * Since 3.2.0 (CU-2, design §13): the request budget of one provider token, shared by every process that uses it — the
 * boards of an import plan, a list sync of another board, the People scan. ClickUp limits requests per token (100 per
 * minute on most plans); this budget keeps the bundle below the last `X-RateLimit-Limit` seen (default 100) less a safety
 * margin (default 10 %), in one-minute windows counted in a PSR-6 pool (`sync.rate_limit.storage`, default `cache.app`).
 *
 * The key is an HMAC of the connection reference with the application secret — never the token. Nothing is counted in
 * memory, so the service is worker-safe. The count is read-modify-write: two processes may both take the last request of
 * a window (the margin covers it), and a 429 is still handled by {@see ProviderHttpClient}. A pool that fails lets the
 * request go (fail open: the provider's own limit still applies).
 */
final readonly class ProviderRateBudget
{
    private const WINDOW = 60;

    /** The limit seen is kept for a day. */
    private const LIMIT_TTL = 86_400;

    public function __construct(
        private CacheItemPoolInterface $pool,
        private string $secret,
        private ClockInterface $clock = new NativeClock(),
        private int $defaultLimit = 100,
        private float $margin = 0.1,
    ) {
    }

    /**
     * Takes one request of the budget: 0 when it may go now, else the seconds until the next window (nothing taken).
     */
    public function reserve(string $budgetKey): int
    {
        $now    = $this->clock->now()->getTimestamp();
        $window = intdiv($now, self::WINDOW);
        try {
            $item  = $this->pool->getItem($this->key($budgetKey) . '_' . $window);
            $used  = $item->isHit() && is_int($item->get()) ? $item->get() : 0;
            $allow = $this->allowed($budgetKey);
            if ($used >= $allow) {
                return max(1, ($window + 1) * self::WINDOW - $now);
            }
            $item->set($used + 1);
            $item->expiresAfter(self::WINDOW * 2);
            $this->pool->save($item);
        } catch (Throwable) {
            return 0;
        }

        return 0;
    }

    /** Records the provider's `X-RateLimit-Limit` of the token (its workspace plan); a value below 1 is ignored. */
    public function observeLimit(string $budgetKey, int $limit): void
    {
        if ($limit < 1) {
            return;
        }
        try {
            $item = $this->pool->getItem($this->key($budgetKey) . '_limit');
            if ($item->isHit() && $item->get() === $limit) {
                return;
            }
            $item->set($limit);
            $item->expiresAfter(self::LIMIT_TTL);
            $this->pool->save($item);
        } catch (Throwable) {
            // The default limit applies.
        }
    }

    /** The requests per minute of the token: the last limit seen, else the default. */
    public function limit(string $budgetKey): int
    {
        try {
            $item = $this->pool->getItem($this->key($budgetKey) . '_limit');
            $seen = $item->isHit() ? $item->get() : null;
        } catch (Throwable) {
            $seen = null;
        }

        return is_int($seen) && $seen > 0 ? $seen : $this->defaultLimit;
    }

    /** The requests per minute the bundle takes: the limit less the margin, at least one. */
    public function allowed(string $budgetKey): int
    {
        return max(1, (int) ($this->limit($budgetKey) * (1 - $this->margin)));
    }

    private function key(string $budgetKey): string
    {
        return 'nowo_task_board_rate_' . hash_hmac('sha256', $budgetKey, $this->secret);
    }
}
