<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Fixtures\RemovedPorts\ImplementsPeopleInviter;

use Nowo\TaskBoardBundle\Sync\Hook\PeopleInviterInterface;

/**
 * 3.0 W1-1 fixture (autodiscovered, NOT loadable): a host class that still implements the removed
 * PeopleInviterInterface. Symfony records "not found while loading" and would drop it unused.
 */
final class OldInviter implements PeopleInviterInterface
{
}
