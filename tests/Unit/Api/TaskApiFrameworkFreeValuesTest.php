<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Api;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRequestContext;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUpload;
use Nowo\TaskBoardBundle\Port\TaskApiRequestContexts;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTimeEntry;
use Nowo\TaskBoardBundle\Sync\Time\ImportedTimeEntryCandidate;
use Nowo\TaskBoardBundle\Tests\Unit\Port\PortFixtures;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Since 2.15.0: the framework-free values of the ports.
 */
final class TaskApiFrameworkFreeValuesTest extends TestCase
{
    use PortFixtures;

    public function testRequestContextHeadersAreCaseInsensitive(): void
    {
        $context = new TaskApiRequestContext('POST', '/api/x', null, null, null, ['x-tenant' => 'acme']);

        self::assertSame('acme', $context->header('X-Tenant'));
        self::assertNull($context->header('X-Other'));
        self::assertSame([], (new TaskApiRequestContext('GET', '/', null, null, null))->headers);
    }

    public function testUploadOfAnEmptyFileWithoutAMimeType(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tb-upload');
        file_put_contents($path, '');
        try {
            $upload = TaskApiRequestContexts::upload(new UploadedFile($path, 'empty.bin', '', null, true));
        } finally {
            unlink($path);
        }

        self::assertSame('application/octet-stream', $upload->clientMimeType);
        self::assertSame(0, $upload->size);
    }

    public function testUploadThatCannotBeOpenedThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The uploaded file "gone.txt" cannot be read.');

        (new TaskApiUpload(sys_get_temp_dir() . '/tb-no-such-upload', 'gone.txt', null, 0))->open();
    }

    public function testTimeEntryCandidateIdAccessors(): void
    {
        $task   = $this->task();
        $person = new TaskSyncPerson($this->directory(), 'p-9', 'Pat', new DateTimeImmutable());
        $entry  = new RemoteTimeEntry('t-1', 'x-1', new RemotePersonRef('p-9', 'Pat'), new DateTimeImmutable(), null, 60);

        $linked = new ImportedTimeEntryCandidate($entry, $person, $task);
        self::assertSame([$person->getId(), 'p-9', $task->getId(), $task->getBoard()->getId()], [$linked->personId(), $linked->personProviderUserId(), $linked->taskId(), $linked->boardId()]);

        $unlinked = new ImportedTimeEntryCandidate($entry, $person, null);
        self::assertSame([null, null], [$unlinked->taskId(), $unlinked->boardId()]);
    }
}
