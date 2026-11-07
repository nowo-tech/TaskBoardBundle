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
 */
interface TaskApiAttachmentsInterface
{
    /**
     * @throws TaskApiRefusal
     */
    public function store(TaskApiPrincipal $principal, Task $task, UploadedFile $file): TaskApiAttachment;
}
