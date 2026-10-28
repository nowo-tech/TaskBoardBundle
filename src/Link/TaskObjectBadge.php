<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Link;

/**
 * Status badge of a host object linked to a task (see {@see TaskObjectLinkResolverInterface::badge()}).
 */
final readonly class TaskObjectBadge
{
    /**
     * @param string $label Text of the badge (already translated)
     * @param string $variant Colour variant: primary, secondary, success, warning, danger, info
     */
    public function __construct(
        public string $label,
        public string $variant = 'secondary',
    ) {
    }
}
