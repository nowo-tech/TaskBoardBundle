<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Contract;

/**
 * Since 3.4.0 (client API v1, tokens/1 `image`, R2, optional): whether the reader may open a host file embedded in a
 * task's text as a Markdown image whose URL is `<client_api.file_scheme>:<fileId>` (Beacon: `beacon-file:<uuid>`).
 * Without it, or for any other image, the token is `{type: "image", visible: false}` (no id, no alt text). The bytes are
 * never served by TaskBoard in 3.4.0 (BD-3 shows a neutral card; the signed URL route is planned for 3.5.0).
 */
interface TaskClientFileResolverInterface
{
    public function canView(object $reader, string $fileId): bool;
}
