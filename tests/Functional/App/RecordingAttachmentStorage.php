<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Hook\StoredAttachment;
use Nowo\TaskBoardBundle\Sync\Hook\TaskAttachmentStorageInterface;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use RuntimeException;

use function count;
use function strlen;

/**
 * Since 1.23: the host's file storage of the functional kernel — keeps the bytes in memory.
 */
final class RecordingAttachmentStorage implements TaskAttachmentStorageInterface
{
    /** @var array<string, string> ref => bytes */
    public static array $files = [];

    /** @var list<string> */
    public static array $deleted = [];

    public static bool $fail = false;

    public function store(Task $task, RemoteAttachment $file, callable $download): StoredAttachment
    {
        if (self::$fail) {
            throw new RuntimeException('storage down');
        }
        $stream = $download();
        $bytes  = (string) stream_get_contents($stream);
        fclose($stream);
        $ref               = 'file-' . (count(self::$files) + 1);
        self::$files[$ref] = $bytes;

        return new StoredAttachment($ref, '/files/' . $ref, $file->name, strlen($bytes), 'application/octet-stream', hash('sha256', $bytes));
    }

    public function delete(Task $task, StoredAttachment $stored): void
    {
        self::$deleted[] = $stored->ref;
    }

    public static function reset(): void
    {
        self::$files   = [];
        self::$deleted = [];
        self::$fail    = false;
    }
}
