<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tenancy;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of {@see FirstBoardProvisionerInterface}: creates a tenant's
 * first board (e.g. through `Service\TaskBoardCreatorInterface`) and returns its id, which then opens, or null to show the
 * empty index. An id the bundle cannot find shows the empty index too.
 *
 * Set `tenancy.first_board_provisioner` to a service implementing it. A service that also implements the deprecated
 * interface is asked through that one until 3.0.
 */
interface FirstBoardProvisioningInterface
{
    public function provision(TaskBoardTenant $tenant, object $user): ?string;
}
