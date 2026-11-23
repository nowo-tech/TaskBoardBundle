<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Fixtures\RemovedPorts\ImplementsBoardAwareImportUsers;

use Nowo\TaskBoardBundle\Import\BoardAwareTaskImportUserResolverInterface;

/**
 * 3.0 W1-1 fixture (autodiscovered, NOT loadable): a host import user resolver that still implements the removed
 * BoardAwareTaskImportUserResolverInterface.
 */
final class OldImportUsers implements BoardAwareTaskImportUserResolverInterface
{
}
