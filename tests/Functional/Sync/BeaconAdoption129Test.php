<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateInterval;
use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Sync\Dto\SyncMode;
use Nowo\TaskBoardBundle\Sync\Dto\SyncOptions;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldSuppression;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldValueEraser;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTimeEntry;
use Nowo\TaskBoardBundle\Sync\SyncRunContext;
use Nowo\TaskBoardBundle\Sync\SyncTaskWriter;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingTimeSink;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedPeopleResolver;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

use function array_replace_recursive;

/**
 * Since 1.29.0: gaps Beacon found when adopting 1.28.0, each reproduced here first.
 */
final class BeaconAdoption129Test extends SyncPeopleFunctionalTestCase
{
    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => [
            'people'             => ['resolver' => 'app.people_resolver'],
            'people_bulk_former' => true,
        ]]);
    }

    protected function setUp(): void
    {
        ScriptedPeopleResolver::reset();
        RecordingTimeSink::reset();
        TestSyncLimitChecker::reset();
        parent::setUp();
    }

    /** 1. A CLI --full brought no hours: nothing could set `SyncOptions::timeEntries`. */
    public function testTheCommandCanReadTimeEntries(): void
    {
        $board  = $this->boardWithColumns('To do');
        $person = new RemotePersonRef('1', 'Ann', 'ann@example.test');
        $this->viewer('ann@example.test');
        ScriptedPeopleResolver::$candidates = ['ann@example.test'];
        ScriptedPeopleResolver::$verified   = ['ann@example.test'];
        $this->workspace([new RemotePerson('1', 'Ann', 'ann@example.test', RemoteRole::Member)]);
        $this->put($this->row('t1', 'Task', 1, createdAt: new DateTimeImmutable('-5 days')));
        $start = (new DateTimeImmutable())->sub(new DateInterval('P2D'));
        $this->provider->withTimeEntries([new RemoteTimeEntry('e1', 't1', $person, $start, $start->add(new DateInterval('PT1H')), 3600, false)]);
        $this->sync($board);
        RecordingTimeSink::reset();

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('nowo:task-board:sync'));

        self::assertSame(Command::SUCCESS, $tester->execute(['boardId' => $board->getId(), '--full' => true]));
        self::assertSame([], RecordingTimeSink::$calls, 'off unless asked');

        self::assertSame(Command::SUCCESS, $tester->execute(['boardId' => $board->getId(), '--full' => true, '--time-entries' => true]));
        self::assertNotSame([], RecordingTimeSink::$calls);
    }

    /** 2. A restored completed task adds nothing to active tasks: the limit is asked for none. */
    public function testRestoringACompletedTaskAsksForNoActiveTask(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->login($this->owner);
        $done = (new Task(board: $board, title: 'Done', creator: $this->owner, column: $board->getColumns()->first() ?: null))->markCompleted()->archive();
        $open = (new Task(board: $board, title: 'Open', creator: $this->owner, column: $board->getColumns()->first() ?: null))->archive();
        $this->em->persist($done);
        $this->em->persist($open);
        $this->em->flush();
        TestSyncLimitChecker::$refuseTasks = true;

        $this->client->request('POST', '/tools/task-board/task/' . $done->getId() . '/restore', ['_token' => PredictableCsrfTokenManager::value('task_board_task_restore')]);
        $this->client->request('POST', '/tools/task-board/task/' . $open->getId() . '/restore', ['_token' => PredictableCsrfTokenManager::value('task_board_task_restore')]);

        $this->em->clear();
        $this->refreshOwner();
        self::assertFalse($this->taskTitled('Done')->isArchived(), 'a completed task adds nothing to the quota');
        self::assertTrue($this->taskTitled('Open')->isArchived(), 'an open one still asks');
    }

    /** 2, the sync's scan: tasks back at the provider without a change count the open ones only. */
    public function testASyncRestoreByTheScanCountsOnlyOpenTasks(): void
    {
        $board = $this->boardWithColumns('To do', 'Finished');
        $this->put($this->row('t1', 'Done', 1), $this->row('t2', 'Open', 2), $this->row('t3', 'Newest', 1_000_000));
        $this->sync($board);
        $this->taskTitled('Done')->markCompleted();
        $this->em->flush();
        $this->provider->removeTask(self::LIST, 't1');
        $this->provider->removeTask(self::LIST, 't2');
        $this->sync($board, allowMassArchive: true);
        self::assertTrue($this->taskTitled('Done')->isArchived());
        $this->put($this->row('t1', 'Done', 1), $this->row('t2', 'Open', 2));
        TestSyncLimitChecker::$calls = [];
        $this->sync($board);
        self::assertContains([1, 0], TestSyncLimitChecker::$calls, 'one open task, not two');
        self::assertNotContains([2, 0], TestSyncLimitChecker::$calls);
        self::assertFalse($this->taskTitled('Done')->isArchived());
    }

    /** 2, the sync's page: a task the provider also says is done counts nothing; one it reopens does. */
    public function testASyncRestoreByThePageCountsWhatBecomesActive(): void
    {
        $board = $this->boardWithColumns('To do', 'Finished');
        $this->put($this->row('t1', 'Done', 1), $this->row('t2', 'Reopened', 2));
        $this->sync($board);
        $this->taskTitled('Done')->markCompleted();
        $this->taskTitled('Reopened')->markCompleted();
        $this->em->flush();
        $this->provider->removeTask(self::LIST, 't1');
        $this->provider->removeTask(self::LIST, 't2');
        $this->sync($board, allowMassArchive: true);
        $done = new RemoteStatus('To do', RemoteStatusType::Done, null, 0);
        $this->put($this->row('t1', 'Done', 3, remoteStatus: $done), $this->row('t2', 'Reopened', 4));
        TestSyncLimitChecker::$calls = [];
        $this->sync($board, full: true);
        self::assertContains([1, 0], TestSyncLimitChecker::$calls);
        self::assertNotContains([2, 0], TestSyncLimitChecker::$calls);
    }

    /**
     * Audit HN-M1: reopening is asked, by every move action, and a refusal moves nothing.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function reopeningMoves(): iterable
    {
        yield 'drag' => ['move'];
        yield 'drag from a fetch' => ['moveXhr'];
        yield 'previous' => ['previous'];
        yield 'column' => ['column'];
    }

    #[DataProvider('reopeningMoves')]
    public function testAMoveThatReopensAsksTheLimit(string $how): void
    {
        $board   = $this->boardWithColumns('To do', 'Finished');
        $columns = [];
        foreach ($board->getColumns() as $column) {
            $columns[$column->getName()] = $column;
        }
        $task = (new Task(board: $board, title: 'Done', creator: $this->owner, column: $columns['Finished']))->markCompleted();
        $this->em->persist($task);
        $this->em->flush();
        [$taskId, $toDo] = [$task->getId(), $columns['To do']->getId()];
        $this->login($this->owner);
        TestSyncLimitChecker::$refuseTasks = true;

        $send = function () use ($how, $taskId, $toDo): void {
            match ($how) {
                'move'     => $this->client->request('POST', '/tools/task-board/task/' . $taskId . '/move', ['columnId' => $toDo, 'position' => 0, '_token' => PredictableCsrfTokenManager::value('task_board_task_move')]),
                'moveXhr'  => $this->client->xmlHttpRequest('POST', '/tools/task-board/task/' . $taskId . '/move', ['columnId' => $toDo, 'position' => 0, '_token' => PredictableCsrfTokenManager::value('task_board_task_move')]),
                'previous' => $this->client->request('POST', '/tools/task-board/task/' . $taskId . '/advance', ['action' => 'previous', '_token' => PredictableCsrfTokenManager::value('task_board_task_advance')]),
                default    => $this->client->request('POST', '/tools/task-board/task/' . $taskId . '/advance', ['columnId' => $toDo, '_token' => PredictableCsrfTokenManager::value('task_board_task_advance')]),
            };
        };
        $send();
        if ($how === 'moveXhr') {
            self::assertResponseStatusCodeSame(409);
            $json = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertIsArray($json);
            self::assertSame('active_tasks', $json['refused']);
            self::assertStringContainsString('limit', (string) $json['message']);
        }
        $this->em->clear();
        $this->refreshOwner();
        $kept = $this->taskTitled('Done');
        self::assertTrue($kept->isCompleted(), 'refused: still completed');
        self::assertSame('Finished', $kept->getColumn()?->getName(), 'and where it was');
        self::assertContains([1, 0], TestSyncLimitChecker::$calls);

        // The refusal shows on the next page.
        $page = $this->client->request('GET', '/tools/task-board/board/' . $board->getId());
        self::assertStringContainsString('reopening it would go over the limit', $page->html());

        TestSyncLimitChecker::$refuseTasks = false;
        $this->login($this->owner);
        $send();
        $this->em->clear();
        $this->refreshOwner();
        self::assertFalse($this->taskTitled('Done')->isCompleted(), 'allowed: it moves');
    }

    /** Moves that do not reopen ask nothing: completing, an open task, within the last column, an archived task. */
    public function testMovesThatReopenNothingAskNothing(): void
    {
        $board   = $this->boardWithColumns('To do', 'Finished');
        $columns = [];
        foreach ($board->getColumns() as $column) {
            $columns[$column->getName()] = $column;
        }
        $open = new Task(board: $board, title: 'Open', creator: $this->owner, column: $columns['To do']);
        $done = (new Task(board: $board, title: 'Done', creator: $this->owner, column: $columns['Finished']))->markCompleted();
        $this->em->persist($open);
        $this->em->persist($done);
        $this->em->flush();
        $this->login($this->owner);
        TestSyncLimitChecker::$refuseTasks = true;
        $move                              = fn (Task $task, string $column): mixed => $this->client->request('POST', '/tools/task-board/task/' . $task->getId() . '/move', ['columnId' => $column, 'position' => 0, '_token' => PredictableCsrfTokenManager::value('task_board_task_move')]);
        $move($done, $columns['Finished']->getId());
        $move($open, $columns['To do']->getId());
        $this->client->request('POST', '/tools/task-board/task/' . $open->getId() . '/advance', ['action' => 'done', '_token' => PredictableCsrfTokenManager::value('task_board_task_advance')]);
        self::assertSame([], TestSyncLimitChecker::$calls);
        $this->em->clear();
        $this->refreshOwner();
        self::assertTrue($this->taskTitled('Open')->isCompleted());
    }

    /** Audit HN-M1: a provider reopen asks; a task the provider also says is done, or still archives, does not. */
    public function testASyncReopenAsksTheLimit(): void
    {
        $board = $this->boardWithColumns('To do', 'Finished');
        $done  = new RemoteStatus('Finished', RemoteStatusType::Done, null, 1);
        $open  = new RemoteStatus('To do', RemoteStatusType::Open, null, 0);
        $this->put($this->row('t1', 'One', 1, status: 'Finished', remoteStatus: $done));
        $this->sync($board);
        self::assertTrue($this->taskTitled('One')->isCompleted());

        $this->put($this->row('t1', 'One', 2, status: 'Finished', remoteStatus: $done));
        TestSyncLimitChecker::$calls = [];
        $this->sync($board, full: true);
        self::assertSame([], array_filter(TestSyncLimitChecker::$calls, static fn (array $call): bool => $call[0] > 0), 'still done');

        TestSyncLimitChecker::$refuseTasks = true;
        $this->put($this->row('t1', 'One', 3, status: 'To do', remoteStatus: $open));
        $report = $this->sync($board, full: true);
        self::assertSame(SyncStatus::Refused, $report->status);
        self::assertTrue($this->taskTitled('One')->isCompleted(), 'refused: nothing written');

        TestSyncLimitChecker::$refuseTasks = false;
        TestSyncLimitChecker::$calls       = [];
        $this->sync($board, full: true);
        self::assertContains([1, 0], TestSyncLimitChecker::$calls);
        self::assertFalse($this->taskTitled('One')->isCompleted());
    }

    /** Audit HNd-L1: completed here, still open at the provider, another field changes: nothing reopens, so nothing is asked. */
    public function testATaskCompletedHereDoesNotRefuseTheSyncPage(): void
    {
        $board = $this->boardWithColumns('To do', 'Finished');
        $open  = new RemoteStatus('To do', RemoteStatusType::Open, null, 0);
        $this->put($this->row('t1', 'One', 1, remoteStatus: $open), $this->row('t2', 'Other', 2, remoteStatus: $open));
        $this->sync($board);
        $this->login($this->owner);
        $this->client->request('POST', '/tools/task-board/task/' . $this->taskTitled('One')->getId() . '/advance', ['action' => 'done', '_token' => PredictableCsrfTokenManager::value('task_board_task_advance')]);
        $this->em->clear();
        $this->refreshOwner();
        self::assertTrue($this->taskTitled('One')->isCompleted());
        TestSyncLimitChecker::$refuseTasks = true;

        foreach ([3, 5] as $updated) {
            $this->put($this->row('t1', 'One changed', $updated, remoteStatus: $open), $this->row('t2', 'Other ' . $updated, $updated + 1, remoteStatus: $open));
            $report = $this->sync($board);
            self::assertSame(SyncStatus::Succeeded, $report->status, 'run at ' . $updated);
            self::assertSame('Other ' . $updated, $this->itemOf('t2')->getTask()?->getTitle(), 'the other task is written');
            self::assertTrue($this->itemOf('t1')->getTask()?->isCompleted(), 'still completed');
        }
    }

    /** Audit HNd-L2: deleting a column can reopen its tasks: asked, and a refusal keeps the column. */
    public function testDeletingAColumnThatReopensAsksTheLimit(): void
    {
        $board   = $this->boardWithColumns('To do', 'Middle', 'Finished');
        $columns = [];
        foreach ($board->getColumns() as $column) {
            $columns[$column->getName()] = $column;
        }
        foreach (['A', 'B'] as $title) {
            $this->em->persist((new Task(board: $board, title: $title, creator: $this->owner, column: $columns['Finished']))->markCompleted());
        }
        $this->em->persist((new Task(board: $board, title: 'Gone', creator: $this->owner, column: $columns['Finished']))->markCompleted()->archive());
        $this->em->flush();
        $id  = $columns['Finished']->getId();
        $url = '/tools/task-board/board/' . $board->getId() . '/column/' . $id . '/delete';
        $this->em->clear();
        $this->refreshOwner();
        $this->login($this->owner);
        TestSyncLimitChecker::$refuseTasks = true;

        $this->client->request('POST', $url, ['_token' => PredictableCsrfTokenManager::value('task_board_column_delete')]);
        self::assertSame([[2, 0]], TestSyncLimitChecker::$calls, 'the two active ones, not the archived one');
        $page = $this->client->followRedirect();
        self::assertStringContainsString('The column was not deleted', $page->html());
        $this->em->clear();
        $this->refreshOwner();
        self::assertInstanceOf(BoardColumn::class, $this->em->find(BoardColumn::class, $id), 'kept');
        self::assertTrue($this->taskTitled('A')->isCompleted(), 'and nothing reopened');
        self::assertSame('Finished', $this->taskTitled('A')->getColumn()?->getName());

        TestSyncLimitChecker::$refuseTasks = false;
        $this->login($this->owner);
        $this->client->request('POST', $url, ['_token' => PredictableCsrfTokenManager::value('task_board_column_delete')]);
        $this->em->clear();
        $this->refreshOwner();
        self::assertNull($this->em->find(BoardColumn::class, $id));
        self::assertFalse($this->taskTitled('A')->isCompleted(), 'allowed: they reopen');
    }

    /** Audit HNd-L2: what reopens nothing asks nothing. */
    public function testDeletingAColumnThatReopensNothingAsksNothing(): void
    {
        $board   = $this->boardWithColumns('First', 'Spare', 'Last');
        $columns = [];
        foreach ($board->getColumns() as $column) {
            $columns[$column->getName()] = $column;
        }
        $this->em->persist(new Task(board: $board, title: 'Open', creator: $this->owner, column: $columns['Spare']));
        $this->em->flush();
        $spare = $columns['Spare']->getId();
        $this->em->clear();
        $this->refreshOwner();
        $this->login($this->owner);
        TestSyncLimitChecker::$refuseTasks = true;
        $this->client->request('POST', '/tools/task-board/board/' . $board->getId() . '/column/' . $spare . '/delete', ['_token' => PredictableCsrfTokenManager::value('task_board_column_delete')]);
        self::assertSame([], TestSyncLimitChecker::$calls);
        $this->em->clear();
        $this->refreshOwner();
        self::assertSame('First', $this->taskTitled('Open')->getColumn()?->getName());

        // The only column left is also the last: its tasks end up completed: nothing reopens.
        $two   = $this->boardWithColumns('Stay', 'Finished');
        $names = [];
        foreach ($two->getColumns() as $column) {
            $names[$column->getName()] = $column;
        }
        $this->em->persist((new Task(board: $two, title: 'Done there', creator: $this->owner, column: $names['Stay']))->markCompleted());
        $this->em->flush();
        [$twoId, $stay] = [$two->getId(), $names['Stay']->getId()];
        $this->em->clear();
        $this->refreshOwner();
        $this->login($this->owner);
        $this->client->request('POST', '/tools/task-board/board/' . $twoId . '/column/' . $stay . '/delete', ['_token' => PredictableCsrfTokenManager::value('task_board_column_delete')]);
        self::assertSame([], TestSyncLimitChecker::$calls);
    }

    /** Audit HN-L1: exact contexts; string ids holding wildcards touch no other member's row. */
    public function testMemberRowsGoByTheExactId(): void
    {
        $board = $this->boardWithColumns('To do');
        $task  = new Task(board: $board, title: 'Mine', creator: $this->owner, column: $board->getColumns()->first() ?: null);
        $this->em->persist($task);
        $member = static fn (string $id, string $label): object => new class($id, $label) {
            public function __construct(private readonly string $id, private readonly string $email)
            {
            }

            public function getId(): string
            {
                return $this->id;
            }

            public function getEmail(): string
            {
                return $this->email;
            }
        };
        $recorder = new TaskChangeRecorder();
        foreach (['axb' => 'x@e.test', 'a_b' => 'u@e.test', 'other' => 'o@e.test'] as $id => $label) {
            $recorder->recordMember($task, $this->owner, TaskChangeType::MemberAdded, $member($id, $label), 'assignee');
        }
        $this->em->flush();
        $reassigner = self::getContainer()->get(TaskSyncPeopleReassigner::class);
        self::assertInstanceOf(TaskSyncPeopleReassigner::class, $reassigner);
        $forget = new ReflectionMethod(TaskSyncPeopleReassigner::class, 'forgetMemberRows');

        $forget->invoke($reassigner, $member('a_b', 'u@e.test'));
        $forget->invoke($reassigner, $member('%', 'nobody@e.test'));

        $db     = $this->em->getConnection();
        $values = $db->fetchFirstColumn("SELECT new_value FROM task_board_task_change_history WHERE change_type = 'member_added' AND new_value IS NOT NULL ORDER BY new_value");
        self::assertSame(['o@e.test', 'x@e.test'], $values);
    }

    /** Audit HN-L4: a new e-mail makes a reviewed person due again; a former person stays decided. */
    public function testAChangedEmailMakesAReviewedPersonDueAgain(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->workspace([new RemotePerson('1', 'Ann', 'ann@remote.test', RemoteRole::Member)]);
        $this->put($this->row('t1', 'One', 1, assignees: [new RemotePersonRef('1', 'Ann', 'ann@remote.test')]));
        $this->sync($board);
        $this->login($this->owner);
        $this->client->request('POST', '/tools/task-board/board/' . $board->getId() . '/connection/people/save?filter=all&page=1', ['task_board_sync_people' => [
            'p_' . $this->personOf('1')->getId() => '',
            '_token'                             => PredictableCsrfTokenManager::value('task_board_connection_people'),
        ]]);
        $this->em->clear();
        $this->refreshOwner();
        $people = self::getContainer()->get(TaskSyncPersonRepository::class);
        self::assertInstanceOf(TaskSyncPersonRepository::class, $people);
        self::assertSame(0, $people->unreviewedCount($this->personOf('1')->getDirectory()));

        $this->workspace([new RemotePerson('1', 'Ann', 'new@remote.test', RemoteRole::Member)]);
        $this->sync($board, full: true);
        $this->em->clear();
        $this->refreshOwner();
        self::assertSame(1, $people->unreviewedCount($this->personOf('1')->getDirectory()));
    }

    /** Info HN-I1: said, not silent. */
    public function testTimeEntriesWarnsWhenItCannotRun(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->put($this->row('t1', 'One', 1));
        $this->sync($board);
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('nowo:task-board:sync'));
        self::assertSame(Command::SUCCESS, $tester->execute(['boardId' => $board->getId(), '--time-entries' => true]));
        self::assertStringContainsString('read no time', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['boardId' => $board->getId()]));
        self::assertStringNotContainsString('read no time', $tester->getDisplay());
    }

    /** 3. A log without the value: after rotating `kernel.secret`, a host repeats the erasure by task and field. */
    public function testEraseByTaskAndField(): void
    {
        $board = $this->boardWithColumns('To do');
        $field = static fn (string $id, string $name, string $text): RemoteCustomField => new RemoteCustomField($id, $name, SyncFieldType::Email, 'email', [], [], $text, $text);
        $row   = static fn (string $id, string $title, string $mail): RemoteTask => new RemoteTask(
            task: new ImportedTaskDto(title: $title, externalId: $id, status: 'To do', priority: TaskPriority::Normal, hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@1790000001'),
            customFieldsText: '- **Contact**: ' . $mail . "\n- **Other**: other@people.test",
            customFields: [$field('f1', 'Contact', $mail), $field('f2', 'Other', 'other@people.test')],
        );
        $this->put($row('t1', 'One', 'zeb@people.test'), $row('t2', 'Two', 'zeb@people.test'));
        $this->sync($board);
        $eraser = self::getContainer()->get(SyncFieldValueEraser::class);
        self::assertInstanceOf(SyncFieldValueEraser::class, $eraser);

        self::assertSame(1, $eraser->eraseField($this->taskTitled('One'), 'Contact'));

        $this->em->clear();
        $this->refreshOwner();
        $left = iterator_to_array($eraser->exportByValue('zeb@people.test', null), false);
        self::assertSame([$this->taskTitled('Two')->getId()], array_values(array_unique(array_column($left, 'taskId'))), 'another task keeps its value');
        self::assertCount(2, iterator_to_array($eraser->exportByValue('other@people.test', null), false), 'another field stays');
        foreach ($this->taskTitled('One')->getDocuments() as $document) {
            self::assertStringNotContainsString('zeb@', $document->getContent(), 'the line goes too');
        }
        self::assertSame(0, $eraser->eraseField($this->taskTitled('One'), 'Contact'), 'nothing left');
        self::assertSame(0, $eraser->eraseField($this->taskTitled('One'), 'Nope'));
    }

    /** 3. In `text` mode the document is the only copy. */
    public function testEraseByTaskAndFieldWithOnlyTheDocument(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->put(new RemoteTask(
            task: new ImportedTaskDto(title: 'One', externalId: 't1', status: 'To do', priority: TaskPriority::Normal, hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@1790000001'),
            customFieldsText: "- **Contact**: zeb@people.test\n- **Other**: keep",
        ));
        $this->sync($board);
        $eraser = self::getContainer()->get(SyncFieldValueEraser::class);
        self::assertInstanceOf(SyncFieldValueEraser::class, $eraser);
        self::assertSame(1, $eraser->eraseField($this->taskTitled('One'), 'Contact'));
    }

    /** 4. Saving "Not mapped" is a decision: the summary's count falls; the person can still match later. */
    public function testSavingNotMappedIsReviewed(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->workspace([new RemotePerson('1', 'Ann', 'ann@remote.test', RemoteRole::Member), new RemotePerson('2', 'Bob', 'bob@remote.test', RemoteRole::Member)]);
        $this->put($this->row('t1', 'One', 1, assignees: [new RemotePersonRef('1', 'Ann', 'ann@remote.test'), new RemotePersonRef('2', 'Bob', 'bob@remote.test')]));
        $this->sync($board);
        $people = self::getContainer()->get(TaskSyncPersonRepository::class);
        self::assertInstanceOf(TaskSyncPersonRepository::class, $people);
        $directory = $this->personOf('1')->getDirectory();
        self::assertSame(2, $people->unreviewedCount($directory));

        $this->login($this->owner);
        $this->client->request('POST', '/tools/task-board/board/' . $board->getId() . '/connection/people/save?filter=all&page=1', ['task_board_sync_people' => [
            'p_' . $this->personOf('1')->getId() => '',
            '_token'                             => PredictableCsrfTokenManager::value('task_board_connection_people'),
        ]]);
        $this->em->clear();
        $this->refreshOwner();
        $directory = $this->personOf('1')->getDirectory();
        self::assertSame(1, $people->unreviewedCount($directory), 'only the one saved');
        self::assertSame(TaskSyncPersonMapping::Unmatched, $this->personOf('1')->getMapping(), 'still free to match by e-mail later');

        // The bulk action still reaches them: it is about who has no mapping, not who was looked at.
        $page = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/connection/people');
        self::assertCount(1, $page->filter('[data-testid="nowo-task-sync-people-former"]'));

        // Saving a mapped person as they are changes nothing.
        $this->viewer('bob@remote.test');
        ScriptedPeopleResolver::$candidates = ['bob@remote.test'];
        $bob                                = $this->em->getRepository(AppUser::class)->findOneBy(['email' => 'bob@remote.test']);
        self::assertInstanceOf(AppUser::class, $bob);
        foreach ([1, 2] as $round) {
            $this->client->request('POST', '/tools/task-board/board/' . $board->getId() . '/connection/people/save?filter=all&page=1', ['task_board_sync_people' => [
                'p_' . $this->personOf('2')->getId() => (string) $bob->getId(),
                '_token'                             => PredictableCsrfTokenManager::value('task_board_connection_people'),
            ]]);
            self::assertSame(TaskSyncPersonMapping::Manual, $this->personOf('2')->getMapping(), 'round ' . $round);
        }

        // A later verified e-mail still maps; the sync's decision makes them due again only by mapping them.
        $this->viewer('ann@remote.test');
        ScriptedPeopleResolver::$candidates = ['ann@remote.test'];
        ScriptedPeopleResolver::$verified   = ['ann@remote.test'];
        $this->sync($board, full: true);
        self::assertSame(TaskSyncPersonMapping::Auto, $this->personOf('1')->getMapping());
    }

    /** 5. The theme slot decides the button. */
    public function testTheFormerButtonUsesTheSlot(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->workspace([new RemotePerson('1', 'Ann', 'ann@remote.test', RemoteRole::Member)]);
        $this->put($this->row('t1', 'One', 1, assignees: [new RemotePersonRef('1', 'Ann', 'ann@remote.test')]));
        $this->sync($board);
        $this->login($this->owner);
        $page = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/connection/people');
        // UiKit's theme gives the slot its class; the fixed one had none.
        self::assertStringContainsString('nowo-ui-btn--secondary', (string) $page->filter('[data-testid="nowo-task-sync-people-former"]')->attr('class'));
    }

    /**
     * 6. The owner's term, in every locale, on the keys hosts reuse.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function formerLabels(): iterable
    {
        yield 'en' => ['en', 'Former person'];
        yield 'es' => ['es', 'Antigua persona'];
        yield 'de' => ['de', 'Ehemalige Person'];
        yield 'fr' => ['fr', 'Ancienne personne'];
        yield 'it' => ['it', 'Ex persona'];
        yield 'nl' => ['nl', 'Voormalig persoon'];
        yield 'pt' => ['pt', 'Antiga pessoa'];
    }

    #[DataProvider('formerLabels')]
    public function testTheFormerLabel(string $locale, string $label): void
    {
        $catalog = Yaml::parseFile(__DIR__ . '/../../../src/Resources/translations/NowoTaskBoardBundle.' . $locale . '.yaml');
        self::assertIsArray($catalog);
        self::assertSame($label, $catalog['task_board.time.former_user'] ?? null);
        self::assertSame($label, $catalog['task_board.history.former_user'] ?? null);
    }

    /** Audit: no id, no memo: an object id can be reused. With an id, one check per slice. */
    public function testTheEmailPathMemoNeedsAnId(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->sync($board);
        $checks = 0;
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use (&$checks): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_VIEW) {
                ++$checks;
            }
        });
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        $connection = $this->connectionOf($board);
        self::assertNotNull($connection);
        $ctx    = new SyncRunContext('run', $fresh, $connection, $this->provider, new ProviderCredential('x', 'c'), new SyncOptions(actor: $this->owner), $this->owner, SyncMode::Full, null, new DateTimeImmutable(), 'clickup', 1000);
        $writer = self::getContainer()->get(SyncTaskWriter::class);
        self::assertInstanceOf(SyncTaskWriter::class, $writer);
        $method = new ReflectionMethod(SyncTaskWriter::class, 'writableUser');

        $noId = new stdClass();
        $method->invoke($writer, $ctx, $noId);
        $method->invoke($writer, $ctx, $noId);
        self::assertSame(2, $checks);

        $checks = 0;
        $method->invoke($writer, $ctx, $this->owner);
        $method->invoke($writer, $ctx, $this->owner);
        self::assertSame(1, $checks);
    }

    /** Audit: a case change of a value does not bring it back; records written by 1.28.0 still hold. */
    public function testSuppressionByValueIgnoresCaseAndKeepsOldRecords(): void
    {
        $suppression = new SyncFieldSuppression('secret');
        self::assertSame($suppression->recordLine('- **A**: Zeb@People.test'), $suppression->recordLine('- **B**: zeb@people.TEST'));
        self::assertNotSame($suppression->recordLine('- **A**: yes'), $suppression->recordLine('- **A**: YES'), 'a short value stays exact, with its field');
        self::assertContains($suppression->record(null, 'value:Zeb@People.test'), $suppression->lineRecords('- **A**: Zeb@People.test'), 'the 1.28.0 record');
    }
}
