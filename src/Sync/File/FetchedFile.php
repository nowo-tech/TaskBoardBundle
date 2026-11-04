<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\File;

use RuntimeException;

use function fopen;
use function is_file;
use function is_resource;
use function unlink;

/**
 * Since 1.23: a remote file already downloaded to a temporary file and checked (size, sniffed type). Deleted by
 * {@see self::delete()} (the sync does it at the end of each file).
 */
final readonly class FetchedFile
{
    public function __construct(
        public string $path,
        public string $name,
        public int $size,
        public string $mime,
        public string $sha256,
    ) {
    }

    /**
     * A read-only stream of the file.
     *
     * @return resource
     */
    public function stream()
    {
        $stream = is_file($this->path) ? fopen($this->path, 'r') : false;
        if (!is_resource($stream)) {
            throw new RuntimeException('The downloaded file is gone.');
        }

        return $stream;
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
