<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Link;

/**
 * Since 3.4.0 (client API v1, §5.3, optional): whether a viewer may see a host-object link of a task (an issue, a chat
 * message, a wiki page…). {@see TaskObjectLinkResolverInterface} has no viewer, so the client API asks this port before
 * it shows a link's label and URL. Without it every host-object link reads `{visible: false}` in the client API (deny by
 * default); the web UI is unchanged.
 */
interface TaskObjectLinkVisibilityInterface
{
    public function visibleTo(object $viewer, string $objectType, string $objectId): bool;
}
