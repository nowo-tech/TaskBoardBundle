<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;

/**
 * Since 1.23: where synced files go (Beacon: StorageKit). The host never fetches a URL: `$download()` returns a
 * read-only stream of a temporary file the bundle already downloaded and checked.
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
