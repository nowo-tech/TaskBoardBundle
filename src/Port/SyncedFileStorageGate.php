<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Hook\StoredAttachment;
use Nowo\TaskBoardBundle\Sync\Hook\SyncedFileStorageInterface;
use Nowo\TaskBoardBundle\Sync\Hook\TaskAttachmentStorageInterface;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;

/**
 * Since 2.15.0: asks a host {@see SyncedFileStorageInterface} where the bundle asks the deprecated
 * {@see TaskAttachmentStorageInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class SyncedFileStorageGate implements TaskAttachmentStorageInterface
{
    public function __construct(private SyncedFileStorageInterface $storage)
    {
    }

    public function store(Task $task, RemoteAttachment $file, callable $download): StoredAttachment
    {
        return $this->storage->store(Snapshots::task($task), $file, $download);
    }

    public function delete(Task $task, StoredAttachment $stored): void
    {
        $this->storage->delete(Snapshots::task($task), $stored);
    }
}
