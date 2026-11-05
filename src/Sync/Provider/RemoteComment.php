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
    ) {
    }
}
