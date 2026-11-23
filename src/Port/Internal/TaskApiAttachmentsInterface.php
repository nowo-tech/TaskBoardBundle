<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentStoreInterface;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiAttachment;
use Nowo\TaskBoardBundle\Entity\Task;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Since 2.2.0 (spec 213): stores a file for a task. All upload checks live here: content sniffing, plan storage, sizes
 * (FR-014). Default: {@see DenyingTaskApiAttachments}, 403 `FILE_001`.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentsInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see TaskApiAttachmentStoreInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface TaskApiAttachmentsInterface
{
    /**
     * @throws TaskApiRefusal
     */
    public function store(TaskApiPrincipal $principal, Task $task, UploadedFile $file): TaskApiAttachment;
}
