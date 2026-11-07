<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tenancy;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 2.0.0 (`tenancy.first_board_provisioner`): creates a tenant's first board. Called on a tenant's empty index, for a
 * user the create check and the quota event accept. Return the board, which then opens, or null to show the empty index.
 */
interface FirstBoardProvisionerInterface
{
    public function provision(TaskBoardTenant $tenant, object $user): ?TaskBoard;
}
