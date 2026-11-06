<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Doctrine\DBAL\Connection;

use function str_contains;

/** @internal The cleaner's connection: runs a hook just before its count, inside the transaction. */
class HookedConnection extends Connection
{
    /** @var (callable(): void)|null */
    public static $beforeCount;

    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        if (self::$beforeCount !== null && str_contains($query, 'COUNT(*)')) {
            $hook              = self::$beforeCount;
            self::$beforeCount = null;
            $hook();
        }

        return parent::fetchOne($query, $params, $types);
    }
}
