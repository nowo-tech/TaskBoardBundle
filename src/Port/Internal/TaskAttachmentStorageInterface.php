<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Hook\StoredAttachment;
use Nowo\TaskBoardBundle\Sync\Hook\SyncedFileStorageInterface;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;

/**
 * Since 1.23: where synced files go (Beacon: StorageKit). The host never fetches a URL: `$download()` returns a
 * read-only stream of a temporary file the bundle already downloaded and checked.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Sync\Hook\TaskAttachmentStorageInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see SyncedFileStorageInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface TaskAttachmentStorageInterface
{
    /**
     * @param callable(): resource $download
     */
    public function store(Task $task, RemoteAttachment $file, callable $download): StoredAttachment;

    /** A stored file the provider replaced or removed. */
    public function delete(Task $task, StoredAttachment $stored): void;
}
