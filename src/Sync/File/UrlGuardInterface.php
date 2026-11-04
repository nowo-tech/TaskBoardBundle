<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\File;

use Nowo\TaskBoardBundle\Sync\Exception\UnsafeRemoteFileException;

/**
 * Since 1.23: an extra address check before a remote file is fetched (e.g. nowo-tech/outbound-url-guard-bundle,
 * which also pins the resolved address). Returns HttpClient options to merge.
 */
interface UrlGuardInterface
{
    /**
     * @throws UnsafeRemoteFileException
     *
     * @return array<string, mixed>
     */
    public function optionsFor(string $url): array;
}
