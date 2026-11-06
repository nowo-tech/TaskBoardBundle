<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\View;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Form\ConnectionRefreshFormType;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Dto\SyncCursor;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderRegistry;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.23: what the board header shows about the provider connection — nothing to someone who cannot view the
 * board, the badge to readers, the badge with *Refresh* and *Connection* (or *Connect provider*) to managers.
 *
 * Since 1.26.3 (TB-SYNC-1) the data also carries the running run: `activeRunId`, `progressUrl` (route
 * `board_connection_progress`, managers only: the endpoint answers 404 to readers), `total` / `processed` of its report
 * and `pollInterval`, so a header can drive the `nowo-task-board-sync-progress` controller. `progress` is
 * `sync.badge.progress`: whether the bundle's own badge renders that bar (a host template may use the fields anyway).
 */
final readonly class ConnectionBadgeProvider
{
    public function __construct(
        private SyncStore $store,
        private TaskBoardResourceAccessChecker $accessChecker,
        private TaskProviderRegistry $providers,
        private FormFactoryInterface $formFactory,
        private ?Security $security = null,
        private ?UrlGeneratorInterface $urlGenerator = null,
        /** @var array<string, array{name: string}> */
        private array $routes = [],
        private bool $showProgress = false,
        private int $pollInterval = 2000,
    ) {
    }

    /**
     * @return array{linked: bool, canManage: bool, providerLabelKey: string|null, containerLabel: string|null, lastSuccessAt: DateTimeImmutable|null, running: bool, refreshForm: FormView|null, activeRunId: string|null, progressUrl: string|null, total: int|null, processed: int, progress: bool, pollInterval: int}|null
     */
    public function forBoard(TaskBoard $board): ?array
    {
        $user = $this->security?->getUser();
        if (!$user instanceof UserInterface || $this->providers->all() === [] || !$this->accessChecker->canViewBoard($user, $board)) {
            return null;
        }
        $canManage  = $this->accessChecker->canManageBoardConnection($user, $board);
        $connection = $this->store->findConnection($board);
        if (!$connection instanceof TaskBoardConnection) {
            return $canManage ? ['linked' => false, 'canManage' => true, 'providerLabelKey' => null, 'containerLabel' => null, 'lastSuccessAt' => null, 'running' => false, 'refreshForm' => null] + $this->runData(null, false) : null;
        }
        $runId = $connection->getRunningRunId() ?? $connection->getCursor()?->runId;

        return [
            'linked'           => true,
            'canManage'        => $canManage,
            'providerLabelKey' => $this->providers->has($connection->getProvider()) ? $this->providers->get($connection->getProvider())->labelKey() : 'task_board.sync.provider.unknown',
            'containerLabel'   => $connection->getContainerLabel(),
            'lastSuccessAt'    => $connection->getLastSuccessAt(),
            'running'          => $connection->getRunningRunId() !== null || $connection->getCursor() instanceof SyncCursor,
            'refreshForm'      => $canManage ? $this->formFactory->create(ConnectionRefreshFormType::class, null, ['with_full' => false])->createView() : null,
        ] + $this->runData($runId !== null ? $this->store->findRun($runId) : null, $canManage);
    }

    /**
     * Since 1.26.3: the running run of the header — its id, the progress endpoint for managers, its counters.
     *
     * @return array{activeRunId: string|null, progressUrl: string|null, total: int|null, processed: int, progress: bool, pollInterval: int}
     */
    private function runData(?TaskSyncRun $run, bool $canManage): array
    {
        $active = $run instanceof TaskSyncRun && !$run->getStatus()->isTerminal() ? $run : null;
        $report = $active?->getReport();
        $route  = $this->routes['board_connection_progress']['name'] ?? null;

        return [
            'activeRunId' => $active?->getId(),
            'progressUrl' => $active instanceof TaskSyncRun && $canManage && $route !== null && $this->urlGenerator instanceof UrlGeneratorInterface
                ? $this->urlGenerator->generate($route, ['boardId' => $active->getBoardId(), 'runId' => $active->getId()])
                : null,
            'total'        => $report?->total,
            'processed'    => $report instanceof SyncReport ? $report->processed() : 0,
            'progress'     => $this->showProgress,
            'pollInterval' => $this->pollInterval,
        ];
    }
}
