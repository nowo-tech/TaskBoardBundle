<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

/**
 * Default resolver: assignees from imports are ignored unless the host app overrides the service.
 */
final class NullTaskImportUserResolver implements TaskImportUserResolverInterface
{
    public function resolveByEmail(string $email): ?object
    {
        return null;
    }
}
