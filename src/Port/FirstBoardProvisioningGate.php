<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisionerInterface;
use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisioningInterface;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;

/**
 * Since 2.15.0: asks a host {@see FirstBoardProvisioningInterface} where the bundle asks the deprecated {@see FirstBoardProvisionerInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class FirstBoardProvisioningGate implements FirstBoardProvisionerInterface
{
    public function __construct(
        private FirstBoardProvisioningInterface $provisioning,
        private TaskBoardRepositoryInterface $boards,
    ) {
    }

    /** The board of the returned id; null for null or an id the bundle cannot find. */
    public function provision(TaskBoardTenant $tenant, object $user): ?TaskBoard
    {
        $id = $this->provisioning->provision($tenant, $user);

        return $id === null ? null : $this->boards->findById($id);
    }
}
