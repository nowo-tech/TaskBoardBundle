<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskErasedEvent;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\Tests\Functional\App\FixtureHttpClient;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Since 1.25: emptying a synced board unlinks it first (no sync can bring the tasks back) and still hands the host the
 * storage refs of the files the sync stored, although the unlink drops the sync attachment rows; time is kept.
 */
final class SyncBoardEraserTest extends SyncFunctionalTestCase
{
    public function testEmptyingASyncedBoardUnlinksItAndReportsTheStoredFiles(): void
    {
        $board = $this->board();
        $this->put(
            $this->remote('f1', 'With file', 1, attachments: [new RemoteAttachment('att-1', 'a.png', 4, 'image/png', 'https://t000.p.clickup-attachments.com/fixture/a.png', new DateTimeImmutable('@1790000000'))]),
            $this->remote('f2', 'Plain', 2),
        );
        FixtureHttpClient::$responder = static fn (): MockResponse => new MockResponse(FixtureHttpClient::PNG, ['http_code' => 200]);
        $this->sync($board);
        $logger = self::getContainer()->get(TaskTimeLogger::class);
        self::assertInstanceOf(TaskTimeLogger::class, $logger);
        $entry = $logger->log($this->taskTitled('With file'), $this->owner, 25, new DateTimeImmutable('2026-11-04'));

        $events     = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_ERASED, static function (TaskErasedEvent $event) use (&$events): void {
            $events[$event->title] = $event;
        });
        $dispatcher->addListener(TaskBoardEvents::BOARD_CONNECTION_UNLINKED, static function () use (&$events): void {
            $events['unlinked'] = true;
        });

        $eraser = self::getContainer()->get('app.board_eraser');
        self::assertInstanceOf(TaskBoardEraser::class, $eraser);
        $fresh = $this->freshBoard($board);
        self::assertTrue($eraser->emptyBoard($fresh, $this->owner)->done);

        self::assertTrue($events['unlinked'] ?? false);
        $withFile = $events['With file'] ?? null;
        self::assertInstanceOf(TaskErasedEvent::class, $withFile);
        self::assertCount(1, $withFile->attachmentRefs);
        self::assertSame(1, $withFile->manualTimeEntries);
        $plain = $events['Plain'] ?? null;
        self::assertInstanceOf(TaskErasedEvent::class, $plain);
        self::assertSame([], $plain->attachmentRefs);

        $this->em->clear();
        self::assertNull($this->connectionOf($board));
        self::assertSame([], $this->em->getRepository(TaskSyncItem::class)->findAll());
        self::assertInstanceOf(TaskBoard::class, $this->em->find(TaskBoard::class, $board->getId()));
        $kept = $this->em->find(TaskTimeEntry::class, $entry->getId());
        self::assertInstanceOf(TaskTimeEntry::class, $kept);
        self::assertTrue($kept->isDetached());
        self::assertSame(25, $kept->getMinutes());
        self::assertSame('f1', $kept->getTaskExternalId());
    }
}
