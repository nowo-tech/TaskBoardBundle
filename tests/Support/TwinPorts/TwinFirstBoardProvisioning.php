<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisionerInterface;
use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisioningInterface;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;

final readonly class TwinFirstBoardProvisioning implements FirstBoardProvisioningInterface
{
    public function __construct(private FirstBoardProvisionerInterface $legacy)
    {
    }

    public function provision(TaskBoardTenant $tenant, object $user): ?string
    {
        return $this->legacy->provision($tenant, $user)?->getId();
    }
}
