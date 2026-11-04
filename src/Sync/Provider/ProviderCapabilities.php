<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.23: what a provider can give.
 */
final readonly class ProviderCapabilities
{
    /**
     * @param list<string> $attachmentHosts exact hosts or "*.suffix" patterns attachment and inline image URLs may use
     */
    public function __construct(
        public bool $comments = false,
        public bool $attachments = false,
        public bool $dependencies = false,
        public bool $customFields = false,
        public bool $subtasks = false,
        public bool $incremental = false,
        public array $attachmentHosts = [],
    ) {
    }
}
