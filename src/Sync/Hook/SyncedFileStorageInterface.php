<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of `TaskAttachmentStorageInterface` (the 2.14 interface, removed in 3.0): where synced files
 * go, with the task as a {@see TaskSnapshot}. `$download()` returns a read-only stream of a temporary file the bundle
 * already downloaded and checked.
 *
 * Set `sync.attachment_storage` to a service implementing it.
 */
interface SyncedFileStorageInterface
{
    /**
     * @param callable(): resource $download
     */
    public function store(TaskSnapshot $task, RemoteAttachment $file, callable $download): StoredAttachment;

    /** A stored file the provider replaced or removed. */
    public function delete(TaskSnapshot $task, StoredAttachment $stored): void;
}
