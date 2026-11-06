<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;

/**
 * Since 1.27.0 (audit GW-L6): a provider that reads a comment's replies with one request per thread, within the run's
 * `sync.comments.max_reply_calls_per_run`.
 */
interface ThreadedCommentProviderInterface extends TaskProviderInterface
{
    /**
     * @throws ProviderException
     *
     * @return iterable<RemoteComment>
     */
    public function fetchCommentsWithin(ProviderCredential $credential, string $taskId, ReplyBudget $budget): iterable;
}
