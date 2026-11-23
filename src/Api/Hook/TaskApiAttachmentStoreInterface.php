<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiAttachment;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUpload;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rules 1 and 3): the framework-free twin of `TaskApiAttachmentsInterface` (the 2.14 interface, removed in 3.0): stores a file
 * for a task, given as a {@see TaskSnapshot} and a {@see TaskApiUpload} instead of the entity and the `UploadedFile`. All
 * upload checks live here, as before (FR-014).
 *
 * Set `api.hooks.attachments` to a service implementing it.
 */
interface TaskApiAttachmentStoreInterface
{
    /**
     * @throws TaskApiRefusal
     */
    public function store(TaskApiPrincipal $principal, TaskSnapshot $task, TaskApiUpload $file): TaskApiAttachment;
}
