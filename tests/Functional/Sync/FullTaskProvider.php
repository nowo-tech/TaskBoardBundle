<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusTime;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskPage;
use Nowo\TaskBoardBundle\Sync\Provider\ReplyBudget;
use Nowo\TaskBoardBundle\Sync\Provider\StatusHistoryProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskDetailProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ThreadedCommentProviderInterface;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;

/**
 * The in-memory provider with a scripted "time in status" (null = not served for that task) and, when set, scripted
 * task pages per cursor ('' = the first page) that ignore the updated-since filter.
 */
final class FullTaskProvider extends InMemoryTaskProvider implements StatusHistoryProviderInterface, TaskDetailProviderInterface, ThreadedCommentProviderInterface
{
    /** @var array<string, list<RemoteStatusTime>|null> */
    public array $history = [];

    public ?ProviderException $historyFailure = null;

    /** @var array<string, RemoteTaskPage> */
    public array $pages = [];

    /** @var array<string, RemoteTask> */
    public array $details = [];

    public int $detailCalls = 0;

    /** @var array<string, ProviderException> */
    public array $detailFailures = [];

    /** Threads each task's comments would read, one request each. */
    public int $threads = 0;

    public function fetchTaskDetail(ProviderCredential $credential, string $taskId): ?RemoteTask
    {
        ++$this->detailCalls;
        if (isset($this->detailFailures[$taskId])) {
            throw $this->detailFailures[$taskId];
        }

        return $this->details[$taskId] ?? null;
    }

    public function fetchCommentsWithin(ProviderCredential $credential, string $taskId, ReplyBudget $budget): iterable
    {
        for ($thread = 0; $thread < $this->threads; ++$thread) {
            $budget->take();
        }

        return $this->fetchComments($credential, $taskId);
    }

    public function fetchTasks(ProviderCredential $credential, string $listId, ?DateTimeImmutable $updatedSince, ?string $cursor): RemoteTaskPage
    {
        return $this->pages[$cursor ?? ''] ?? parent::fetchTasks($credential, $listId, $updatedSince, $cursor);
    }

    public function fetchStatusHistory(ProviderCredential $credential, string $taskId): ?array
    {
        if ($this->historyFailure instanceof ProviderException) {
            throw $this->historyFailure;
        }

        return $this->history[$taskId] ?? null;
    }
}
