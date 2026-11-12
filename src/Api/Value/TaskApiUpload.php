<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentStoreInterface;
use RuntimeException;

use function fopen;
use function sprintf;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 3): a file uploaded to `POST /v2/task/{id}/attachment`, as the framework-free
 * {@see TaskApiAttachmentStoreInterface} sees it. The bundle already checked that the
 * upload succeeded; every other check (content sniffing, plan storage, sizes) is the host's, as before.
 *
 * `$clientName` and `$clientMimeType` come from the client: untrusted. `$pathname` is the temporary file PHP wrote; it is
 * removed at the end of the request, so store a copy, never the path.
 */
final readonly class TaskApiUpload
{
    public function __construct(
        public string $pathname,
        public string $clientName,
        public ?string $clientMimeType,
        public int $size,
    ) {
    }

    /**
     * A read-only stream of the temporary file; the caller closes it.
     *
     * @throws RuntimeException when the file cannot be opened
     *
     * @return resource
     */
    public function open()
    {
        $stream = @fopen($this->pathname, 'r');
        if ($stream === false) {
            throw new RuntimeException(sprintf('The uploaded file "%s" cannot be read.', $this->clientName));
        }

        return $stream;
    }
}
