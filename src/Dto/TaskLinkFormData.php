<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Enum\TaskLinkType;

/**
 * Form payload for attaching an external link to a task.
 */
final class TaskLinkFormData
{
    public function __construct(
        public TaskLinkType $linkType = TaskLinkType::Url,
        public string $url = '',
        public ?string $label = null,
        public ?string $externalId = null,
    ) {
    }
}
