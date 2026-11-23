<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisioningInterface;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;

/**
 * Since 2.0.0 (`tenancy.first_board_provisioner`): creates a tenant's first board. Called on a tenant's empty index, for a
 * user the create check and the quota event accept. Return the board, which then opens, or null to show the empty index.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisionerInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see FirstBoardProvisioningInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface FirstBoardProvisionerInterface
{
    public function provision(TaskBoardTenant $tenant, object $user): ?TaskBoard;
}
