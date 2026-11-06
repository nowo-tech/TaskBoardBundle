<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use DateTimeImmutable;

/**
 * Since 1.23: a provider comment (body in Markdown, already sanitized by the adapter).
 */
final readonly class RemoteComment
{
    /**
     * @param list<string> $inlineImageUrls
     */
    public function __construct(
        public string $id,
        public string $body,
        public ?string $authorEmail,
        public ?string $authorName,
        public DateTimeImmutable $createdAt,
        public array $inlineImageUrls = [],
        /** Since 1.25: the author as a person of the source (`authorEmail` / `authorName` stay for BC). */
        public ?RemotePersonRef $author = null,
        /** Since 1.27.0 (TB-SYNC-3): the comment this one replies to (null = top level). */
        public ?string $parentId = null,
        /**
         * Since 1.27.0: the files attached to the comment (copied like task attachments).
         *
         * @var list<RemoteAttachment>
         */
        public array $attachments = [],
        /** Since 1.27.0: an assigned comment (action item): its assignee and whether it is resolved. */
        public ?RemotePersonRef $assignee = null,
        public ?bool $resolved = null,
        /** Since 1.27.0: when the provider says the comment was last edited (null = unknown). */
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }
}
