<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisionerInterface;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;

/** Since 2.0.0: a host's first-board provisioning. The tenant `empty` gets none. */
final class RecordingBoardProvisioner implements FirstBoardProvisionerInterface
{
    public int $calls = 0;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function provision(TaskBoardTenant $tenant, object $user): ?TaskBoard
    {
        ++$this->calls;
        if ($tenant->key === 'empty') {
            return null;
        }
        $board = new TaskBoard('First of ' . $tenant->key, 'first-' . $tenant->key, $user);
        $board->setTenantKey($tenant->key);
        $this->em->persist($board);
        $this->em->flush();

        return $board;
    }
}
