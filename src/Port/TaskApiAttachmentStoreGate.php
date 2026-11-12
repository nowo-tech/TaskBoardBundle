<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentsInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentStoreInterface;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiAttachment;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\Task;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Since 2.15.0: asks a host {@see TaskApiAttachmentStoreInterface} where the bundle asks the deprecated {@see TaskApiAttachmentsInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class TaskApiAttachmentStoreGate implements TaskApiAttachmentsInterface
{
    public function __construct(private TaskApiAttachmentStoreInterface $store)
    {
    }

    public function store(TaskApiPrincipal $principal, Task $task, UploadedFile $file): TaskApiAttachment
    {
        return $this->store->store($principal, Snapshots::task($task), TaskApiRequestContexts::upload($file));
    }
}
