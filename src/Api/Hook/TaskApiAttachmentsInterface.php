<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiAttachment;
use Nowo\TaskBoardBundle\Entity\Task;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Since 2.2.0 (spec 213): stores a file for a task. All upload checks live here: content sniffing, plan storage, sizes
 * (FR-014). Default: {@see DenyingTaskApiAttachments}, 403 `FILE_001`.
 *
 * @deprecated since 2.15.0, implement the framework-free {@see TaskApiAttachmentStoreInterface} instead (REQ-ARCH-001); removed in 3.0. A
 *             service implementing this interface is still asked through it, exactly as before.
 */
interface TaskApiAttachmentsInterface
{
    /**
     * @throws TaskApiRefusal
     */
    public function store(TaskApiPrincipal $principal, Task $task, UploadedFile $file): TaskApiAttachment;
}
