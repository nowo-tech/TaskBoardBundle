<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Contract;

use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientReference;

/**
 * Since 3.4.0 (client API v1, tokens/1 `reference`, R2, optional): a host object named in a task's description, comment
 * or question context with the syntax `#[title](type:id)` (ChatKit's), as the reader may see it now. Without it every
 * reference reads `{visible: false}` (deny by default). The title written in the text is never shown: only the title the
 * host returns for this reader. References inside code are never asked.
 *
 * Called at most once per distinct `type:id` and reader in a request (the bundle memoises per request only). Beacon
 * reuses the reference rules of its chat.
 */
interface TaskClientReferenceResolverInterface
{
    /** The object as the reader may see it; null when it does not exist or the reader may not open it (one answer). */
    public function reference(object $reader, string $type, string $id): ?TaskClientReference;
}
