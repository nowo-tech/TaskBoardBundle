<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\View;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Form\ConnectionRefreshFormType;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Dto\SyncCursor;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderRegistry;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.23: what the board header shows about the provider connection — nothing to someone who cannot view the
 * board, the badge to readers, the badge with *Refresh* and *Connection* (or *Connect provider*) to managers.
 */
final readonly class ConnectionBadgeProvider
{
    public function __construct(
        private SyncStore $store,
        private TaskBoardResourceAccessChecker $accessChecker,
        private TaskProviderRegistry $providers,
        private FormFactoryInterface $formFactory,
        private ?Security $security = null,
    ) {
    }

    /**
     * @return array{linked: bool, canManage: bool, providerLabelKey: string|null, containerLabel: string|null, lastSuccessAt: DateTimeImmutable|null, running: bool, refreshForm: FormView|null}|null
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
            return $canManage ? ['linked' => false, 'canManage' => true, 'providerLabelKey' => null, 'containerLabel' => null, 'lastSuccessAt' => null, 'running' => false, 'refreshForm' => null] : null;
        }

        return [
            'linked'           => true,
            'canManage'        => $canManage,
            'providerLabelKey' => $this->providers->has($connection->getProvider()) ? $this->providers->get($connection->getProvider())->labelKey() : 'task_board.sync.provider.unknown',
            'containerLabel'   => $connection->getContainerLabel(),
            'lastSuccessAt'    => $connection->getLastSuccessAt(),
            'running'          => $connection->getRunningRunId() !== null || $connection->getCursor() instanceof SyncCursor,
            'refreshForm'      => $canManage ? $this->formFactory->create(ConnectionRefreshFormType::class, null, ['with_full' => false])->createView() : null,
        ];
    }
}
