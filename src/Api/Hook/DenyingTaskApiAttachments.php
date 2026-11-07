<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiAttachment;
use Nowo\TaskBoardBundle\Entity\Task;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Since 2.2.0 (spec 213): the default: no uploads, until the host sets `api.hooks.attachments`.
 */
final readonly class DenyingTaskApiAttachments implements TaskApiAttachmentsInterface
{
    public function store(TaskApiPrincipal $principal, Task $task, UploadedFile $file): TaskApiAttachment
    {
        throw new TaskApiRefusal(403, 'FILE_001', 'Attachments are not available');
    }
}
