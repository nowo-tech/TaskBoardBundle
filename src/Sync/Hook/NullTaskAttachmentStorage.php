<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use LogicException;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;

/**
 * Since 1.23: no storage configured — the sync counts every file as refused (`storage_disabled`) and never calls
 * these methods.
 */
final readonly class NullTaskAttachmentStorage implements TaskAttachmentStorageInterface
{
    public function store(Task $task, RemoteAttachment $file, callable $download): StoredAttachment
    {
        throw new LogicException('No attachment storage is configured.');
    }

    public function delete(Task $task, StoredAttachment $stored): void
    {
    }
}
