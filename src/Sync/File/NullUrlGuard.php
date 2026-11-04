<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\File;

/**
 * Since 1.23: no extra check (the fetcher's own client still refuses private networks).
 */
final readonly class NullUrlGuard implements UrlGuardInterface
{
    public function optionsFor(string $url): array
    {
        return [];
    }
}
