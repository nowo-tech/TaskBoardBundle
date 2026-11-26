<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Contract;

use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientSource;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientSourceLink;

/**
 * Since 3.4.0 (client API v1, §6.2, optional): `POST tasks` with a `source` (Beacon: `{type: "chat_message",
 * conversationUuid, messageUuid}`). The host checks that **this person can read that source now** and answers the host
 * object the new task links to; null refuses it (`422 source_not_visible`, the same answer for an unknown source).
 * Without it, `source` is `400 invalid_field`.
 *
 * Called once per create, before the task is written, outside any lock: do no write here.
 */
interface TaskClientSourceLinkInterface
{
    public function link(TaskClientPrincipal $principal, TaskClientSource $source): ?TaskClientSourceLink;
}
