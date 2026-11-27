<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Model;

/**
 * Since 3.4.0 (client API v1): the host object a new task links to for its source (a `host_object` link, shown to other
 * readers through `TaskObjectLinkVisibilityInterface`), with an optional label.
 */
final readonly class TaskClientSourceLink
{
    public function __construct(
        public string $objectType,
        public string $objectId,
        public ?string $label = null,
    ) {
    }
}
