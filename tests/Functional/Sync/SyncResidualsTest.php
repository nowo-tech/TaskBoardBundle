<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldValueEraser;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusTime;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;

use function array_column;
use function sort;

/**
 * Since 1.28.0: audit residuals GWf-I1, GWd-L4, I6, `time_in_status`, detach of the history.
 */
final class SyncResidualsTest extends SyncPeopleFunctionalTestCase
{
    public function testExportNamesEveryLinkOfAPerson(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->workspace([self::member('7')]);
        $this->put($this->row('t1', 'One', 1, creator: self::ref('7'), assignees: [self::ref('7')], watchers: [self::ref('7')]));
        $this->provider->setComments('t1', [new RemoteComment('c1', 'Said', null, 'User 7', new DateTimeImmutable('@1790000100'), author: self::ref('7'))]);
        $this->viewer('u7@example.test');
        $this->sync($board);
        $people = self::getContainer()->get(TaskSyncPersonRepository::class);
        self::assertInstanceOf(TaskSyncPersonRepository::class, $people);

        $export = $people->exportPerson($this->personOf('7'));
        $taskId = $this->taskTitled('One')->getId();
        self::assertSame([$taskId], $export['tasksCreated']);
        $comment = $this->em->getRepository(TaskComment::class)->findOneBy([]);
        self::assertInstanceOf(TaskComment::class, $comment);
        self::assertSame([['taskId' => $taskId, 'commentId' => $comment->getId()]], $export['commentsWritten']);
        $roles = array_column($export['taskRoles'], 'role');
        sort($roles);
        self::assertSame(['assignee', 'watcher'], $roles);
        self::assertSame($taskId, $export['taskRoles'][0]['taskId']);
    }

    public function testErasureReachesTheFieldsDocumentButNeverSomeonesOwnDocument(): void
    {
        $board = $this->boardWithColumns('To do');
        $row   = new RemoteTask(
            task: new ImportedTaskDto(title: 'Doc', externalId: 't1', status: 'To do', priority: TaskPriority::Normal, hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@1790000001'),
            customFieldsText: "- **Contact**: zebulon@people.test\n- **Keep**: fine",
        );
        $this->put($row);
        $this->sync($board);
        $task = $this->taskTitled('Doc');
        $this->em->persist(new TaskDocument($task, 'My notes (ClickUp)', "Call zebulon@people.test\n- **Contact**: zebulon@people.test", $this->owner, 5));
        $this->em->flush();
        $eraser = self::getContainer()->get(SyncFieldValueEraser::class);
        self::assertInstanceOf(SyncFieldValueEraser::class, $eraser);

        self::assertSame(1, $eraser->eraseByValue('ZEBULON@people.test', $board));

        $this->em->clear();
        $this->refreshOwner();
        $contents = [];
        foreach ($this->taskTitled('Doc')->getDocuments() as $document) {
            $contents[$document->getTitle()] = $document->getContent();
        }
        self::assertStringContainsString('zebulon', $contents['My notes (ClickUp)'], 'not the shape of the fields document');
        foreach ($contents as $title => $content) {
            if ($title !== 'My notes (ClickUp)') {
                self::assertSame('- **Keep**: fine', $content);
            }
        }

        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        self::assertSame(1, $eraser->eraseBoard($fresh));
        $this->em->clear();
        $this->refreshOwner();
        foreach ($this->taskTitled('Doc')->getDocuments() as $document) {
            if ($document->getTitle() !== 'My notes (ClickUp)') {
                self::assertSame('', $document->getContent());
            }
        }
    }

    public function testATaskDetailWithoutTheAttachmentsKeyLeavesNothingPending(): void
    {
        $board    = $this->boardWithColumns('To do');
        $provider = new DetailTaskProvider();
        $row      = new RemoteTask(
            task: new ImportedTaskDto(title: 'No files', externalId: 't1', status: 'To do', priority: TaskPriority::Normal, hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@1790000001'),
            attachmentsKnown: false,
        );
        $provider->putTask(self::LIST, $row);
        $provider->withTaskDetail(clone $row);
        $report = $this->sync($board, $provider);
        self::assertSame(1, $report->stats['attachments.unread'] ?? 0, 'not claimed as read on one answer');
        self::assertTrue($this->itemOf('t1')->hasPendingRead(TaskSyncItem::PENDING_ATTACHMENTS));

        // Neither answer of a full sync says anything: no files, no longer pending. No more requests after that.
        $full = $this->sync($board, $provider, full: true);
        self::assertSame(0, $full->stats['attachments.unread'] ?? 0);
        self::assertFalse($this->itemOf('t1')->hasPendingRead(TaskSyncItem::PENDING_ATTACHMENTS));
    }

    /** A reply that does not move the task's date arrives on a full sync, through `reply_count`. */
    public function testRepliesKeptAreCountedAgainstTheThread(): void
    {
        $board             = $this->boardWithColumns('To do');
        $provider          = new FullTaskProvider();
        $provider->threads = 1;
        $provider->putTask(self::LIST, $this->row('t1', 'One', 1));
        $provider->setComments('t1', [
            new RemoteComment('c1', 'Top', null, 'Bob', new DateTimeImmutable('@1790000100')),
            new RemoteComment('r1', 'Reply', null, 'Bob', new DateTimeImmutable('@1790000200'), parentId: 'c1'),
        ]);
        $this->sync($board, $provider);
        self::assertSame(SyncStatus::Succeeded, $this->sync($board, $provider, full: true)->status);
        self::assertSame(2, $this->rows(TaskComment::class));
    }

    public function testStatusHistoryUnavailableOutlivesTheRunThatFoundItUntilItIsRead(): void
    {
        $board    = $this->boardWithColumns('To do');
        $provider = new FullTaskProvider();
        $provider->putTask(self::LIST, $this->row('t1', 'One', 1));
        $provider->historyFailure = new ProviderException(ProviderErrorCode::Forbidden);
        $this->sync($board, $provider);
        $this->sync($board, $provider);
        self::assertTrue($this->connectionOf($board)?->isStatusHistoryUnavailable());
        $this->login($this->owner);
        $page = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/connection');
        self::assertSame('unavailable', $page->filter('[data-completeness-key="time_in_status"]')->attr('data-completeness-status'));

        $provider->historyFailure = null;
        $provider->history['t1']  = [new RemoteStatusTime('To do', null, 1, true)];
        $this->sync($board, $provider, full: true);
        self::assertFalse($this->connectionOf($board)?->isStatusHistoryUnavailable());
    }

    public function testDetachUserTakesTheHistoryOffTheUserKeepingTheFact(): void
    {
        $board = $this->boardWithColumns('To do');
        $bob   = $this->viewer('bob@example.test');
        $task  = new Task(board: $board, title: 'Mine', creator: $this->owner, column: $board->getColumns()->first() ?: null);
        $this->em->persist($task);
        (new TaskChangeRecorder())->record($task, $bob, TaskChangeType::TimeRemoved, '60');
        $this->em->flush();
        $reassigner = self::getContainer()->get(TaskSyncPeopleReassigner::class);
        self::assertInstanceOf(TaskSyncPeopleReassigner::class, $reassigner);

        $reassigner->detachUser($bob, $this->owner);
        $row = $this->em->getRepository(TaskChangeHistory::class)->findOneBy(['changeType' => TaskChangeType::TimeRemoved]);
        self::assertInstanceOf(TaskChangeHistory::class, $row);
        self::assertSame([null, 'bob@example.test'], [$row->getUser(), $row->getUserName()]);

        $reassigner->detachUser($bob, $this->owner, anonymise: false);
        $this->em->clear();
        $this->refreshOwner();
        self::assertInstanceOf(TaskChangeHistory::class, $this->em->getRepository(TaskChangeHistory::class)->findOneBy(['changeType' => TaskChangeType::TimeRemoved]));
    }
}
