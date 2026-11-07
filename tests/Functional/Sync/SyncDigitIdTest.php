<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncAttachment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Tests\Functional\App\FixtureHttpClient;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_filter;
use function array_map;
use function array_values;
use function json_encode;
use function sort;
use function str_starts_with;

/**
 * Since 2.0.1 (audit HY-B): PHP turns an all-digit array key into an integer, and `strict_types` refuses it as a string. One such
 * remote id failed the whole sync; so did an all-digit attachment id once it was tracked.
 */
final class SyncDigitIdTest extends SyncFunctionalTestCase
{
    public function testAnAllDigitIdIsImportedBesideTheOthers(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $this->put(
            $this->remote('42', 'Digits'),
            $this->remote('007', 'Leading zeros'),
            $this->remote('abc', 'Letters'),
            $this->remote('43', 'Child of digits', parent: '42'),
            $this->remote('44', 'Depends on digits', dependsOn: [['42', TaskDependencyType::BlockedBy]]),
        );

        $report = $this->sync($board);

        self::assertSame(SyncStatus::Succeeded, $report->status, 'errors: ' . json_encode($report->errors));
        self::assertSame(5, $report->created);
        $this->em->clear();
        $titles = array_map(static fn (Task $task): string => $task->getTitle(), $this->em->getRepository(Task::class)->findBy(['board' => $board->getId()]));
        sort($titles);
        self::assertSame(['Child of digits', 'Depends on digits', 'Digits', 'Leading zeros', 'Letters'], $titles);
        // '007' stays itself: it is not 7.
        $ids = array_map(static fn (TaskSyncItem $item): string => $item->getExternalId(), $this->em->getRepository(TaskSyncItem::class)->findBy(['boardId' => $board->getId()]));
        sort($ids);
        self::assertSame(['007', '42', '43', '44', 'abc'], $ids);
        $child = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Child of digits']);
        self::assertSame('Digits', $child?->getParent()?->getTitle());
    }

    public function testASecondRunUpdatesAndAMissingOneIsArchived(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $this->put($this->remote('42', 'Digits'), $this->remote('43', 'Other'));
        $this->sync($board);

        $this->put($this->remote('42', 'Digits renamed', 2), $this->remote('43', 'Other', 2));
        $report = $this->sync($board);

        self::assertSame(SyncStatus::Succeeded, $report->status, 'errors: ' . json_encode($report->errors));
        self::assertSame(1, $report->updated);
        self::assertCount(2, $this->em->getRepository(Task::class)->findBy(['board' => $board->getId()]));
    }

    /** The tracked files are keyed by their remote id, and the loop that drops a file reads the key as text. */
    public function testAnAllDigitAttachmentIdSurvivesTheNextRun(): void
    {
        $board                        = $this->board(columns: ['To do', 'Done']);
        $url                          = 'https://t000.p.clickup-attachments.com/fixture/9/a.pdf';
        FixtureHttpClient::$responder = static fn (): MockResponse => new MockResponse('%PDF', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/pdf']]);
        $file                         = new RemoteAttachment('777', 'a.pdf', 4, 'application/pdf', $url, new DateTimeImmutable('@1790000000'));
        $this->put($this->remote('a1', 'With a file', attachments: [$file]));
        $this->provider->setComments('a1', [new RemoteComment('c1', 'Hello', 'owner@example.com', 'Owner', new DateTimeImmutable('@1790000100'))]);
        self::assertSame(SyncStatus::Succeeded, $this->sync($board)->status);
        self::assertCount(1, $this->em->getRepository(TaskSyncAttachment::class)->findAll());

        // Listed again with another file: the old one has an integer key in the "no longer listed" loop.
        $other = new RemoteAttachment('888', 'b.pdf', 4, 'application/pdf', $url, new DateTimeImmutable('@1790000000'));
        $this->put($this->remote('a1', 'With a file', 2, attachments: [$other]));
        $this->provider->setComments('a1', [new RemoteComment('c1', 'Hello again', 'owner@example.com', 'Owner', new DateTimeImmutable('@1790000200'))]);
        $report = $this->sync($board);

        self::assertSame(SyncStatus::Succeeded, $report->status, 'errors: ' . json_encode($report->errors));
        $this->em->clear();
        $remoteIds = array_map(static fn (TaskSyncAttachment $file): string => $file->getRemoteId(), $this->em->getRepository(TaskSyncAttachment::class)->findAll());
        self::assertSame(['888'], $remoteIds);
    }

    /** The log says where an unexpected failure came from: class, file and line, never its message or the data in it. */
    public function testAnUnexpectedFailureIsLoggedWithItsPlaceAndNoData(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $this->put($this->remote('a1', 'Secret title'));
        $this->sync($board);
        $this->put($this->remote('a1', 'Secret title', 2));
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (): never {
            throw new RuntimeException('secret-value-from-the-message');
        });

        $report = $this->sync($board, full: true);

        self::assertSame(SyncStatus::Failed, $report->status);
        $records = array_values(array_filter($this->logger()->records, static fn (array $record): bool => str_starts_with($record['message'], 'TaskBoard sync') && isset($record['context']['exception'])));
        self::assertNotSame([], $records);
        self::assertSame(RuntimeException::class, $records[0]['context']['exception']);
        self::assertMatchesRegularExpression('/^[A-Za-z]+\.php:\d+$/', (string) ($records[0]['context']['at'] ?? ''));
        self::assertStringNotContainsString('secret-value', (string) json_encode($records));
    }
}
