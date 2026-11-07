<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use LogicException;

use function sprintf;

/**
 * Since 2.1.0 (spec 214, audit HY-L2): a scope's numbers cannot be purged while its tasks still carry them.
 */
final class TaskNumberScopeInUseException extends LogicException
{
    public static function forScope(string $scopeKey, int $tasks): self
    {
        return new self(sprintf('Scope "%s" still has %d task(s) with a number: delete them before purging it.', $scopeKey, $tasks));
    }
}
