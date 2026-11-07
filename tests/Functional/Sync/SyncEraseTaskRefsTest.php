<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskErasedEvent;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\Tests\Functional\App\FixtureHttpClient;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingAttachmentStorage;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_keys;

/**
 * Since 2.2.0, audit IA-M1 (proof test by the auditor): a task erased after its board was unlinked still names the files the
 * sync stored, as deleteBoard() does since GWd-M1, and leaves no row behind on any database.
 */
final class SyncEraseTaskRefsTest extends SyncPeopleFunctionalTestCase
{
    private const PDF = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

    public function testEraseTaskAfterUnlinkNamesTheStoredFiles(): void
    {
        $board                        = $this->board();
        FixtureHttpClient::$responder = static fn (): MockResponse => new MockResponse(self::PDF, ['http_code' => 200]);
        $this->put($this->remote('t1', 'Has file', attachments: [new RemoteAttachment('att1', 'own.pdf', 30, 'application/pdf', 'https://t000.p.clickup-attachments.com/att1/own.pdf', new DateTimeImmutable('@1790000000'))]));
        $this->sync($board);
        self::assertSame(1, $this->rows(TaskSyncAttachment::class));

        $this->service()->unlink($this->freshBoard($board), $this->owner);
        $this->em->clear();
        $this->refreshOwner();
        self::assertSame(1, $this->rows(TaskSyncAttachment::class), 'row outlives the unlink (GWd-M1)');

        $refs = [];
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ERASED, static function (TaskErasedEvent $event) use (&$refs): void {
            $refs = [...$refs, ...$event->attachmentRefs];
        });
        $eraser = self::getContainer()->get('app.board_eraser');
        self::assertInstanceOf(TaskBoardEraser::class, $eraser);
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Has file']);
        self::assertInstanceOf(Task::class, $task);
        self::assertSame(1, $eraser->eraseTask($task, $this->owner));

        self::assertCount(1, $refs, 'the host learns of the stored file');
        self::assertSame(array_keys(RecordingAttachmentStorage::$files), $refs);
        $this->em->clear();
        self::assertSame(0, $this->rows(TaskSyncAttachment::class), 'no orphaned row, whatever the database enforces');

        // Named once: a later deletion of the board has nothing more to name.
        $later = [];
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ERASED, static function (TaskErasedEvent $event) use (&$later): void {
            $later = [...$later, ...$event->attachmentRefs];
        });
        $eraser->deleteBoard($this->freshBoard($board), $this->owner);
        self::assertSame([], $later);
    }
}
