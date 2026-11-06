<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.27.0 (audit GW-L6): the reply requests a run may still spend, shared with the adapter for one task's comments.
 */
final class ReplyBudget
{
    public int $used = 0;

    /** Threads left unread because the budget ran out. */
    public int $skipped = 0;

    public function __construct(private readonly int $limit, private readonly bool $alreadyRead = false)
    {
    }

    /** Since 1.27.0: this version of the task's threads was read: no request, nothing to report. */
    public static function alreadyRead(): self
    {
        return new self(0, true);
    }

    /** True when one more request may be made (and counts it). */
    public function take(): bool
    {
        if ($this->used >= $this->limit) {
            $this->skipped += $this->alreadyRead ? 0 : 1;

            return false;
        }
        ++$this->used;

        return true;
    }
}
