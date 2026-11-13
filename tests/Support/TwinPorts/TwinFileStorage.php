<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\Sync\Hook\StoredAttachment;
use Nowo\TaskBoardBundle\Sync\Hook\SyncedFileStorageInterface;
use Nowo\TaskBoardBundle\Sync\Hook\TaskAttachmentStorageInterface;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

final readonly class TwinFileStorage implements SyncedFileStorageInterface
{
    public function __construct(private TaskAttachmentStorageInterface $legacy, private TwinLookup $lookup)
    {
    }

    public function store(TaskSnapshot $task, RemoteAttachment $file, callable $download): StoredAttachment
    {
        return $this->legacy->store($this->lookup->task($task->id), $file, $download);
    }

    public function delete(TaskSnapshot $task, StoredAttachment $stored): void
    {
        $this->legacy->delete($this->lookup->task($task->id), $stored);
    }
}
