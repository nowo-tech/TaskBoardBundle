<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use Nowo\TaskBoardBundle\Service\TaskBoardQuota;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlanItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Http\ProviderRateBudget;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Nowo\TaskBoardBundle\Sync\Provider\PeopleProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskPage;
use Nowo\TaskBoardBundle\Sync\Provider\SourceProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccessMode;
use Nowo\TaskBoardBundle\Sync\Repository\ImportPlanStore;
use Nowo\TaskBoardBundle\Sync\Status\StatusColumns;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function array_filter;
use function array_map;
use function array_sum;
use function ceil;
use function count;
use function in_array;
use function intdiv;
use function max;

/**
 * Since 3.2.0 (CU-2, design §8.7): the dry run of a plan from the provider — no write. Per item it reads at most
 * `sync.plans.preview_max_pages` task pages and counts tasks, subtasks, closed and archived tasks and declared attachment
 * sizes; beyond that the counts are scaled from the source's task count and marked estimated. Then the requests the
 * import needs (task pages, a comment read per task when comments are imported, a detail read for one task in ten)
 * and the minutes at the token's rate ({@see ProviderRateBudget}), the host's board quota, the time access of the
 * token (Q-CU-15) and the tenant's earlier plans of the workspace (Q-CU-12).
 */
final readonly class ImportPlanPreviewer
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private ImportPlanService $plans,
        private ImportPlanStore $store,
        private TaskBoardRepositoryInterface $boards,
        private TaskBoardQuota $quota = new TaskBoardQuota(),
        private ?ProviderRateBudget $budget = null,
        private ?TaskSyncPersonRepository $people = null,
        private bool $timeSink = false,
        private int $previewMaxPages = 3,
        /** @var list<array{name: string, color: ?string, icon?: ?string}> */
        private array $defaultColumns = TaskBoardCreator::DEFAULT_COLUMNS,
        // Since the audit ZBY-L4: one preview page view reads at most this many provider requests, for this long; the
        // boards past it are shown unread (`preview_truncated`).
        private int $maxRequests = 60,
        private int $maxSeconds = 15,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function preview(TaskSyncImportPlan $plan, object $actor): PlanPreview
    {
        $provider   = $this->plans->provider($plan);
        $credential = $this->plans->credential($plan);
        $items      = [];
        $requests   = 0;
        $warnings   = [];
        $calls      = 0;
        $deadline   = $this->clock->now()->getTimestamp() + $this->maxSeconds;
        foreach ($this->store->items($plan) as $item) {
            if ($calls >= $this->maxRequests || $this->clock->now()->getTimestamp() >= $deadline) {
                $items[] = ['estimated' => true, 'error' => 'preview_truncated'] + $this->row($item);
                if (!in_array(PlanPreview::WARNING_TRUNCATED, $warnings, true)) {
                    $warnings[] = PlanPreview::WARNING_TRUNCATED;
                }
                continue;
            }
            $row     = $this->item($provider, $credential, $item, $calls);
            $items[] = $row;
            $requests += $this->requests($plan, $row);
            if ($row['estimated'] && !in_array(PlanPreview::WARNING_ESTIMATED, $warnings, true)) {
                $warnings[] = PlanPreview::WARNING_ESTIMATED;
            }
        }
        $workspaceId = (string) $plan->getWorkspaceId();
        $active      = $this->store->activeFor($plan->getTenantKey(), $plan->getProvider(), $workspaceId);
        if ($active instanceof TaskSyncImportPlan && $active->getId() !== $plan->getId()) {
            $warnings[] = PlanPreview::WARNING_WORKSPACE_BUSY;
        }
        if ($this->store->workspaceImportedBefore($plan)) {
            $warnings[] = PlanPreview::WARNING_WORKSPACE_BEFORE;
        }
        if ($this->timeSink && $plan->option(TaskSyncImportPlan::OPTION_TIME, false) && $provider instanceof PeopleProviderInterface) {
            try {
                if ($provider->timeAccess($credential, $workspaceId)->mode === TimeAccessMode::Member) {
                    $warnings[] = PlanPreview::WARNING_TIME_MEMBER;
                }
            } catch (ProviderException) {
                $warnings[] = PlanPreview::WARNING_TIME_MEMBER;
            }
        }
        $people = $this->people($plan->getDirectory());
        if ($people['unreviewed'] > 0) {
            $warnings[] = PlanPreview::WARNING_PEOPLE_UNREVIEWED;
        }
        $newBoards = count(array_filter($items, static fn (array $row): bool => $row['newBoard']));
        $refusal   = $newBoards > 0 ? $this->quota->refusal(TaskBoardQuotaCheckEvent::ACTION_BOARD_CREATE, $plan->getTenantKey(), null, $newBoards, $actor) : null;
        $perMinute = $this->budget?->allowed($plan->getProvider() . ':' . $plan->getConnectionRef()) ?? 90;

        return new PlanPreview($items, $requests, $perMinute, $warnings, $refusal, $people);
    }

    /**
     * @return array{itemId: string, label: string, board: string, newBoard: bool, tasks: int, subtasks: int, closed: int, archived: int, attachments: int, attachmentBytes: int, columns: int, estimated: bool, error: string|null}
     */
    private function item(TaskProviderInterface $provider, ProviderCredential $credential, TaskSyncImportPlanItem $item, int &$calls): array
    {
        $row = $this->row($item);
        try {
            ++$calls;
            $source = $provider instanceof SourceProviderInterface ? $provider->findSource($credential, $item->getSourceId()) : null;
            $cursor = null;
            $pages  = 0;
            do {
                ++$calls;
                $page = $this->page($provider, $credential, $item, $source, $cursor);
                ++$pages;
                foreach ($page->tasks as $task) {
                    ++$row['tasks'];
                    $row['subtasks'] += $task->task->parentExternalId !== null ? 1 : 0;
                    $row['closed'] += $task->status?->type?->isDone() === true ? 1 : 0;
                    $row['archived'] += $task->archived ? 1 : 0;
                    $row['attachments'] += count($task->attachments);
                    $row['attachmentBytes'] += array_sum(array_map(static fn (RemoteAttachment $file): int => $file->size ?? 0, $task->attachments));
                }
                $cursor = $page->lastPage ? null : $page->nextCursor;
            } while ($cursor !== null && $pages < $this->previewMaxPages && $calls < $this->maxRequests);
        } catch (ProviderException $exception) {
            return ['error' => $exception->errorCode->value] + $row;
        }
        if ($cursor !== null) {
            // More pages than read: scaled from the source's count when it has one, else at least one more page.
            $known = $source->openTaskCount ?? 0;
            $scale = max($row['tasks'] + self::PAGE_SIZE, $known) / max(1, $row['tasks']);
            foreach (['tasks', 'subtasks', 'closed', 'archived', 'attachments', 'attachmentBytes'] as $key) {
                $row[$key] = (int) ceil($row[$key] * $scale);
            }
            $row['estimated'] = true;
        }

        return $row;
    }

    /**
     * An item's row before anything is read.
     *
     * @return array{itemId: string, label: string, board: string, newBoard: bool, tasks: int, subtasks: int, closed: int, archived: int, attachments: int, attachmentBytes: int, columns: int, estimated: bool, error: string|null}
     */
    private function row(TaskSyncImportPlanItem $item): array
    {
        return [
            'itemId'  => $item->getId(), 'label' => $item->getSourceLabel(), 'board' => $item->getBoardName(), 'newBoard' => $item->isCreateBoard(),
            'tasks'   => 0, 'subtasks' => 0, 'closed' => 0, 'archived' => 0, 'attachments' => 0, 'attachmentBytes' => 0,
            'columns' => $this->columnsToCreate($item), 'estimated' => false, 'error' => null,
        ];
    }

    private function page(TaskProviderInterface $provider, ProviderCredential $credential, TaskSyncImportPlanItem $item, ?RemoteSource $source, ?string $cursor): RemoteTaskPage
    {
        return $source instanceof RemoteSource && $provider instanceof SourceProviderInterface
            ? $provider->fetchSourceTasks($credential, $source, null, $cursor)
            : $provider->fetchTasks($credential, $item->getSourceId(), null, $cursor);
    }

    /**
     * @param array{tasks: int, attachments: int} $row
     */
    private function requests(TaskSyncImportPlan $plan, array $row): int
    {
        $tasks = $row['tasks'];

        return intdiv($tasks + self::PAGE_SIZE - 1, self::PAGE_SIZE) + 2
            + ($plan->option(TaskSyncImportPlan::OPTION_COMMENTS, true) ? $tasks : 0)
            + intdiv($tasks, 10);
    }

    /** The statuses of the map that need a new column on the target board. */
    private function columnsToCreate(TaskSyncImportPlanItem $item): int
    {
        $names = [];
        if ($item->isCreateBoard()) {
            foreach ($this->defaultColumns as $column) {
                $names[] = StatusColumns::normalize($column['name']);
            }
        } else {
            $board = $item->getBoardId() !== null ? $this->boards->findById($item->getBoardId()) : null;
            foreach ($board instanceof TaskBoard ? $board->getColumns() : [] as $column) {
                $names[] = StatusColumns::normalize($column->getName());
            }
        }
        $count = 0;
        foreach ($item->getStatusMap() as $entry) {
            $count += $entry['column'] === StatusColumns::CREATE && !in_array(StatusColumns::normalize($entry['status']), $names, true) ? 1 : 0;
        }

        return $count;
    }

    /**
     * @return array{mapped: int, unreviewed: int, total: int}
     */
    private function people(?TaskSyncDirectory $directory): array
    {
        if (!$directory instanceof TaskSyncDirectory || !$this->people instanceof TaskSyncPersonRepository) {
            return ['mapped' => 0, 'unreviewed' => 0, 'total' => 0];
        }
        $counts = $this->people->mappingCounts($directory);

        return [
            'mapped'     => ($counts[TaskSyncPersonMapping::Auto->value] ?? 0) + ($counts[TaskSyncPersonMapping::Manual->value] ?? 0),
            'unreviewed' => $this->people->unreviewedCount($directory),
            'total'      => array_sum($counts),
        ];
    }
}
