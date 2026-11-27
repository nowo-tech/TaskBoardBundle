<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Model;

/**
 * Since 3.4.0 (client API v1, tokens/1): a host object named in a task's text (`#[title](type:id)`) as the reader may
 * see it — its type, id, the host's title for this reader and, optionally, a same-origin or `https://` URL. Returned by
 * `TaskClientReferenceResolverInterface` only for an object the reader may open now.
 */
final readonly class TaskClientReference
{
    /** Longest title kept (characters). */
    public const TITLE_MAX = 200;

    public string $title;

    public function __construct(
        public string $type,
        public string $id,
        string $title,
        public ?string $url = null,
    ) {
        $this->title = TaskClientContext::clean($title, self::TITLE_MAX);
    }
}
