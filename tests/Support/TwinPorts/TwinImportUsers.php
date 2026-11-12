<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\Import\BoardAwareTaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Import\BoardScopedTaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

final readonly class TwinImportUsers implements BoardScopedTaskImportUserResolverInterface
{
    public function __construct(private BoardAwareTaskImportUserResolverInterface $legacy, private TwinLookup $lookup)
    {
    }

    public function resolveByEmail(string $email): ?object
    {
        return $this->legacy->resolveByEmail($email);
    }

    public function resolveByEmailOnBoard(string $email, TaskBoardSnapshot $board): ?object
    {
        return $this->legacy->resolveByEmailForBoard($email, $this->lookup->board($board->id));
    }
}
