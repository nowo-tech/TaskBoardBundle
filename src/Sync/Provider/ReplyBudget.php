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

    /** Since 1.28.0: {@see self::wants()} found a thread longer than what is kept. */
    private bool $grown = false;

    /**
     * @param array<string, int> $storedReplies comment id => replies kept here (with an already read version)
     */
    public function __construct(private readonly int $limit, private readonly bool $alreadyRead = false, private readonly array $storedReplies = [])
    {
    }

    /** Since 1.27.0: this version of the task's threads was read: no request, nothing to report. */
    public static function alreadyRead(): self
    {
        return new self(0, true);
    }

    /**
     * Since 1.28.0: nobody has confirmed that a new reply moves ClickUp's `date_updated`. With the version already read, a
     * comment whose `reply_count` is above what is kept here is still read, within the run's limit.
     *
     * @param array<string, int> $storedReplies comment id => replies kept here
     */
    public static function alreadyReadExcept(array $storedReplies, int $limit): self
    {
        return new self($limit, true, $storedReplies);
    }

    /** Whether this thread needs a request at all. An adapter that never asks keeps the 1.27 rule: no request. */
    public function wants(string $commentId, int $replyCount): bool
    {
        if (!$this->alreadyRead) {
            return true;
        }
        // @igor-ignore - Built per sync run, not a shared service.
        $this->grown = $replyCount > ($this->storedReplies[$commentId] ?? 0);

        return $this->grown;
    }

    /** True when one more request may be made (and counts it). */
    public function take(): bool
    {
        if ($this->alreadyRead && !$this->grown) {
            return false;
        }
        // @igor-ignore - Built per sync run, not a shared service.
        $this->grown = false;
        if ($this->used >= $this->limit) {
            // @igor-ignore - Built per sync run, not a shared service.
            $this->skipped += $this->alreadyRead ? 0 : 1;

            return false;
        }
        // @igor-ignore - Built per sync run, not a shared service.
        ++$this->used;

        return true;
    }
}
