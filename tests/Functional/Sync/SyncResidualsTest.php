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
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function iterator_to_array;
use function ksort;
use function sort;

/**
 * Since 1.28.0: audit residuals GWf-I1, GWd-L4, I6, `time_in_status`, detach of the history.
 */
final class SyncResidualsTest extends SyncPeopleFunctionalTestCase
{
    private const BASE = '/tools/task-board/';

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
        $eraser->eraseBoard($fresh);
        $this->em->clear();
        $this->refreshOwner();
        foreach ($this->taskTitled('Doc')->getDocuments() as $document) {
            if ($document->getTitle() !== 'My notes (ClickUp)') {
                self::assertSame('', $document->getContent());
            }
        }
    }

    public function testTheEraserTrustsTheMarkNotTheShapeAndErasedLinesDoNotReturn(): void
    {
        $board = $this->boardWithColumns('To do');
        $text  = "- **Contact**: zebulon@people.test\n- **Keep**: fine";
        $this->put($this->fieldRow(1, $text));
        $this->sync($board);
        $task = $this->taskTitled('Doc');
        // A person's document in the same shape, and a free line added to the sync's own.
        $this->em->persist(new TaskDocument($task, 'Clients (ACME)', '- **Ana**: ana@client.test', $this->owner, 5));
        $this->em->flush();
        foreach ($task->getDocuments() as $document) {
            if ($document->isSyncFields()) {
                $document->setContent($document->getContent() . "\nCall zebulon@people.test first");
            }
        }
        $this->em->flush();
        $eraser = self::getContainer()->get(SyncFieldValueEraser::class);
        self::assertInstanceOf(SyncFieldValueEraser::class, $eraser);

        // The export finds what the eraser can (`both` here: no typed row for these lines).
        $found = iterator_to_array($eraser->exportByValue('zebulon@people.test', $board), false);
        self::assertSame(['Contact'], array_column($found, 'field'));
        self::assertSame('zebulon@people.test', $found[0]['text']);
        self::assertSame($task->getId(), $found[0]['taskId']);

        self::assertSame(2, $eraser->eraseByValue('zebulon@people.test', $board));
        $this->assertDocuments(['Clients (ACME)' => '- **Ana**: ana@client.test', 'sync' => '- **Keep**: fine']);

        // The same line from the provider does not return; a different value does.
        $this->put($this->fieldRow(2, $text));
        $this->sync($board, full: true);
        $this->assertDocuments(['Clients (ACME)' => '- **Ana**: ana@client.test', 'sync' => '- **Keep**: fine']);
        $this->put($this->fieldRow(3, "- **Contact**: other@people.test\n- **Keep**: fine"));
        $this->sync($board, full: true);
        $this->assertDocuments(['Clients (ACME)' => '- **Ana**: ana@client.test', 'sync' => "- **Contact**: other@people.test\n- **Keep**: fine"]);

        // A whole board: the person's document stays.
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        $eraser->eraseBoard($fresh);
        $this->assertDocuments(['Clients (ACME)' => '- **Ana**: ana@client.test', 'sync' => '']);
    }

    public function testADocumentFromBeforeTheMarkIsRecognisedOnlyStrictly(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->put($this->row('t1', 'Doc', 1));
        $this->sync($board);
        $task = $this->taskTitled('Doc');
        $this->em->persist(new TaskDocument($task, 'Campos (ClickUp)', '- **Contact**: zebulon@people.test', $this->owner, 1));
        $this->em->persist(new TaskDocument($task, 'Campos (ACME)', '- **Contact**: zebulon@people.test', $this->owner, 4));
        $this->em->persist(new TaskDocument($task, 'Fields (ClickUp) draft', '- **Contact**: zebulon@people.test', $this->owner, 2));
        $this->em->persist(new TaskDocument($task, 'Felder (ClickUp)', "My notes\n- **Contact**: zebulon@people.test", $this->owner, 3));
        $this->em->flush();
        $eraser = self::getContainer()->get(SyncFieldValueEraser::class);
        self::assertInstanceOf(SyncFieldValueEraser::class, $eraser);
        self::assertSame(1, $eraser->eraseByValue('zebulon@people.test', $board));
        $this->em->clear();
        $this->refreshOwner();
        $contents = [];
        foreach ($this->taskTitled('Doc')->getDocuments() as $document) {
            $contents[$document->getTitle()] = [$document->getContent(), $document->isSyncFields()];
        }
        self::assertSame(['', true], $contents['Campos (ClickUp)']);
        self::assertSame(['- **Contact**: zebulon@people.test', false], $contents['Campos (ACME)'], 'a provider this bundle does not write');
        self::assertFalse($contents['Fields (ClickUp) draft'][1]);
        self::assertStringContainsString('zebulon', $contents['Fields (ClickUp) draft'][0]);
        self::assertStringContainsString('zebulon', $contents['Felder (ClickUp)'][0]);
    }

    /** Audit HId-L4: the id decides, so taking a colleague's label and being erased does not clear the colleague's rows. */
    public function testMemberHistoryIsClearedByTheMembersIdNotByALabelTheyShare(): void
    {
        $board = $this->boardWithColumns('To do');
        $ana   = $this->viewer('ana@example.test')->setDisplayName('Same Name');
        $eve   = $this->viewer('eve@example.test')->setDisplayName('Same Name');
        $this->em->flush();
        $task = new Task(board: $board, title: 'Mine', creator: $this->owner, column: $board->getColumns()->first() ?: null);
        $this->em->persist($task);
        $recorder = new TaskChangeRecorder();
        $recorder->recordMember($task, $this->owner, TaskChangeType::MemberAdded, $ana, 'assignee');
        $recorder->recordMember($task, $this->owner, TaskChangeType::MemberAdded, $eve, 'assignee');
        // A row from before 1.29: only the label.
        $recorder->record($task, $this->owner, TaskChangeType::MemberRemoved, 'Same Name', null, 'assignee');
        $this->em->flush();
        $reassigner = self::getContainer()->get(TaskSyncPeopleReassigner::class);
        self::assertInstanceOf(TaskSyncPeopleReassigner::class, $reassigner);

        $reassigner->detachUser($eve, $this->owner, anonymise: true);

        $db    = $this->em->getConnection();
        $table = 'task_board_task_change_history';
        self::assertSame('Same Name', $db->fetchOne('SELECT new_value FROM ' . $table . " WHERE change_type = 'member_added' AND context = ?", ['assignee user:' . $ana->getId()]), 'not the other person');
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM ' . $table . " WHERE change_type = 'member_added' AND new_value IS NULL AND context IS NULL"), 'hers: the name and the id go, the fact stays');
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM ' . $table . " WHERE change_type = 'member_removed' AND old_value IS NULL"), 'an older row has only the label');

        // The panel shows the role, not the id.
        $this->login($this->owner);
        $this->client->request('GET', self::BASE . 'task/' . $task->getId());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('user:' . $ana->getId(), (string) $this->client->getResponse()->getContent());
    }

    public function testAnonymisingForgetsTheNameInMemberHistoryRows(): void
    {
        $board = $this->boardWithColumns('To do');
        $bob   = $this->viewer('bob@example.test');
        $task  = new Task(board: $board, title: 'Mine', creator: $this->owner, column: $board->getColumns()->first() ?: null);
        $this->em->persist($task);
        $recorder = new TaskChangeRecorder();
        $recorder->record($task, $this->owner, TaskChangeType::MemberAdded, null, 'bob@example.test');
        $recorder->record($task, $this->owner, TaskChangeType::MemberRemoved, 'bob@example.test');
        $recorder->record($task, $this->owner, TaskChangeType::MemberAdded, null, 'keep@example.test');
        $this->em->flush();
        $reassigner = self::getContainer()->get(TaskSyncPeopleReassigner::class);
        self::assertInstanceOf(TaskSyncPeopleReassigner::class, $reassigner);

        $reassigner->detachUser($bob, $this->owner, anonymise: true);

        $table = 'task_board_task_change_history';
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table . " WHERE new_value = 'bob@example.test' OR old_value = 'bob@example.test'"));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table . " WHERE new_value = 'keep@example.test'"));
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table . " WHERE change_type IN ('member_added', 'member_removed')"), 'the fact stays');
    }

    /** Audit HI-L5: the same words in another format do not mark the comment edited; other words do. */
    public function testARendererRewriteIsNotAnEdit(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->put($this->row('t1', 'One', 1));
        $this->provider->setComments('t1', [
            new RemoteComment('c1', 'one two [link](https://x.test/a(b))', null, 'Bob', new DateTimeImmutable('@1790000100'), author: self::ref('7')),
            new RemoteComment('c2', 'before', null, 'Bob', new DateTimeImmutable('@1790000101'), author: self::ref('7')),
        ]);
        $this->workspace([self::member('7')]);
        $this->sync($board);
        $this->provider->setComments('t1', [
            new RemoteComment('c1', "- one\n- **two** [link](https://x.test/a%28b%29)", null, 'Bob', new DateTimeImmutable('@1790000100'), author: self::ref('7')),
            new RemoteComment('c2', 'after', null, 'Bob', new DateTimeImmutable('@1790000101'), author: self::ref('7')),
        ]);
        $report = $this->sync($board, full: true);
        self::assertSame(2, $report->commentsUpdated);
        $this->em->clear();
        $this->refreshOwner();
        $edited = [];
        foreach ($this->em->getRepository(TaskComment::class)->findAll() as $comment) {
            $edited[$comment->getBody()] = $comment->getEditedAt() !== null;
        }
        ksort($edited);
        self::assertSame(["- one\n- **two** [link](https://x.test/a%28b%29)" => false, 'after' => true], $edited);
    }

    /**
     * Audit HId-L1: what a reader relies on is an edit, even with the same words.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function realEdits(): iterable
    {
        yield 'link target' => ['[pay here](https://ok.test/invoice)', '[pay here](https://evil.test/invoice)'];
        yield 'image target' => ['![i](https://ok.test/a.png)', '![i](https://evil.test/a.png)'];
        yield 'number at the start of a line' => ['100. approved', '900. approved'];
        yield 'word spacing' => ['no tes', 'notes'];
        yield 'underscore in a target' => ['[pay](https://ok.test/a_b)', '[pay](https://ok.test/ab)'];
        yield 'asterisk in a target' => ['[pay](https://ok.test/a*b)', '[pay](https://ok.test/ab)'];
        yield 'tilde in an image target' => ['![i](https://ok.test/~a.png)', '![i](https://ok.test/a.png)'];
    }

    #[DataProvider('realEdits')]
    public function testARealEditIsMarked(string $before, string $after): void
    {
        $board = $this->boardWithColumns('To do');
        $this->put($this->row('t1', 'One', 1));
        $this->provider->setComments('t1', [new RemoteComment('c1', $before, null, 'Bob', new DateTimeImmutable('@1790000100'), author: self::ref('7'))]);
        $this->workspace([self::member('7')]);
        $this->sync($board);
        $this->provider->setComments('t1', [new RemoteComment('c1', $after, null, 'Bob', new DateTimeImmutable('@1790000100'), author: self::ref('7'))]);
        $this->sync($board, full: true);
        $this->em->clear();
        $this->refreshOwner();
        $comment = $this->em->getRepository(TaskComment::class)->findOneBy([]);
        self::assertInstanceOf(TaskComment::class, $comment);
        self::assertSame($after, $comment->getBody());
        self::assertNotNull($comment->getEditedAt());
    }

    /** Audit HId-L3: renaming the field does not bring an identifying value back; a short value stays tied to its field. */
    public function testARenamedFieldDoesNotBringTheValueBackAndAShortValueTouchesNoOtherField(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->put($this->fieldRow(1, "- **Contact**: zeb@people.test\n- **Phone**: +34600000000\n- **Flag**: yes\n- **Other**: yes"));
        $this->sync($board);
        $eraser = self::getContainer()->get(SyncFieldValueEraser::class);
        self::assertInstanceOf(SyncFieldValueEraser::class, $eraser);
        $eraser->eraseByValue('zeb@people.test', $board);
        $eraser->eraseByValue('+34600000000', $board);
        $eraser->eraseByValue('**Flag**', $board);
        $this->assertDocuments(['sync' => '- **Other**: yes']);

        $this->put($this->fieldRow(2, "- **Contacto**: zeb@people.test\n- **Mobile**: +34600000000\n- **Flag**: yes\n- **Other**: yes\n- **Renamed**: yes"));
        $this->sync($board, full: true);
        $this->assertDocuments(['sync' => "- **Other**: yes\n- **Renamed**: yes"]);
    }

    private function fieldRow(int $updated, string $text): RemoteTask
    {
        return new RemoteTask(
            task: new ImportedTaskDto(title: 'Doc', externalId: 't1', status: 'To do', priority: TaskPriority::Normal, hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@' . (1_790_000_000 + $updated)),
            customFieldsText: $text,
        );
    }

    /**
     * @param array<string, string> $expected title ("sync" = the marked one) => content
     */
    private function assertDocuments(array $expected): void
    {
        $this->em->clear();
        $this->refreshOwner();
        $found = [];
        foreach ($this->taskTitled('Doc')->getDocuments() as $document) {
            $found[$document->isSyncFields() ? 'sync' : $document->getTitle()] = $document->getContent();
        }
        self::assertEquals($expected, $found);
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
