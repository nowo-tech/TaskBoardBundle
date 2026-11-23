<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\User\TaskBoardUserDirectoryInterface;

/**
 * Since 2.32.0: the `user_directory` of a test that runs with `database.user_reference: id` and needs no host user behind
 * a reference (labels show the id).
 */
final class NoUserDirectory implements TaskBoardUserDirectoryInterface
{
    public function find(string $id): ?object
    {
        return null;
    }
}
