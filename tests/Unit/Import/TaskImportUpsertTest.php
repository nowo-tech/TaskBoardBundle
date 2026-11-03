<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpCsvImporter;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportOptions;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportResult;
use Nowo\TaskBoardBundle\Import\NullTaskImportUserResolver;
use Nowo\TaskBoardBundle\Import\TaskBoard\TaskBoardCsvImporter;
use Nowo\TaskBoardBundle\Import\TaskImportLinkLookupInterface;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_filter;
use function array_slice;
use function count;
use function str_contains;
use function str_starts_with;

/** Since 1.22: the upsert paths of the orchestrator that the database tests do not reach. */
final class TaskImportUpsertTest extends TestCase
{
    private TestUser $user;

    private TaskBoard $board;

    /** @var list<Task> */
    private array $tasks = [];

    private int $flushes = 0;

    protected function setUp(): void
    {
        $this->user  = new TestUser('1', 'dev');
        $this->board = new TaskBoard('Demo', 'demo', $this->user);
        $this->board->addColumn(new BoardColumn($this->board, 'To do', 0));
        $this->tasks   = [];
        $this->flushes = 0;
    }

    public function testARepeatedIdIsSkippedAndAMissingParentKeepsTheTask(): void
    {
        $existing = $this->imported('Existing', 'A');

        $result = $this->importCsv("Task ID,Task Name,Parent ID\nA,Existing,ZZ\nA,Again,\n");

        self::assertSame([0, 0, 1, 1], [$result->created, $result->updated, $result->unchanged, $result->skipped]);
        self::assertTrue($this->warned($result, 'id "A" repeats an earlier row'));
        self::assertTrue($this->warned($result, 'parent "ZZ" was not found; the task keeps its parent'));
        self::assertNull($existing->getParent());
        self::assertSame(0, $this->flushes, 'nothing changed: nothing written');
    }

    public function testARowFollowsItsSourceParentButNeverUnderItsOwnDescendant(): void
    {
        $parent = $this->imported('Parent', 'P');
        $child  = $this->imported('Child', 'C');
        $child->setParent($parent);
        $other = $this->imported('Other', 'O');

        $moved = $this->importCsv("Task ID,Task Name,Parent ID\nC,Child,O\n");
        self::assertSame(1, $moved->updated);
        self::assertSame($other, $child->getParent());
        self::assertFalse($parent->getSubtasks()->contains($child));
        self::assertTrue($other->getSubtasks()->contains($child));

        $loop = $this->importCsv("Task ID,Task Name,Parent ID\nO,Other,C\nP,Parent,P\n");
        self::assertSame(2, $loop->unchanged);
        self::assertSame(2, count(array_filter($loop->warnings, static fn (string $w): bool => str_contains($w, 'its own ancestor'))));
        self::assertNull($other->getParent());

        $created = $this->importCsv("Task ID,Task Name,Parent ID\nN,New,O\n");
        self::assertSame(1, $created->created);
        self::assertSame($other, $this->tasks[count($this->tasks) - 1]->getParent(), 'a new row hangs from a task imported before');
    }

    public function testADependencyAddedOnReimportCountsAsAnUpdate(): void
    {
        $this->imported('First', '1');
        $this->imported('Second', '2');

        $result = $this->importCsv("Task ID,Task Name,Waiting On,Blocking\n1,First,,\n2,Second,1,\n2,Renamed,,\n", withDependencies: true);

        self::assertSame(1, $result->dependenciesCreated);
        self::assertSame(1, $result->updated, 'Second gains a relation; the renamed row repeats id 2 and is skipped');
        self::assertSame(1, $result->unchanged);
        self::assertSame(1, $result->skipped);

        $again = $this->importCsv("Task ID,Task Name,Blocking\n1,First,2\n", withDependencies: true);
        self::assertSame(1, $again->unchanged, 'First blocks Second is the same relation: silent');
        self::assertSame([], $again->warnings);
    }

    public function testIdsImportedIntoAnotherBoardAreSkippedAndListedOnce(): void
    {
        $lookup = $this->createMock(TaskImportLinkLookupInterface::class);
        $lookup->expects(self::once())->method('findOnOtherBoards')
            ->with($this->board, 'clickup', self::callback(static fn (array $ids): bool => array_slice($ids, 0, 6) === ['1', '2', '3', '4', '5', '6'] && count($ids) === 7 && str_starts_with($ids[6], 'fp:')))
            ->willReturn(['1' => true, '2' => true, '3' => true, '4' => true, '5' => true, '6' => true]);
        $this->imported('Here', '7');

        $result = $this->importCsv("Task ID,Task Name\n1,a\n2,b\n3,c\n4,d\n5,e\n6,f\n7,Here\n,No id\n", lookup: $lookup);

        self::assertSame([1, 6, 1], [$result->created, $result->skipped, $result->unchanged]);
        self::assertTrue($this->warned($result, '6 row(s) were skipped: already imported elsewhere (an import never moves or copies tasks between boards).'));
        self::assertFalse($this->warned($result, '"a"'), 'no title of a skipped row');
    }

    public function testTheLookupIsNotAskedWithoutIdsToCheckOrForTheOwnFormat(): void
    {
        $lookup = $this->createMock(TaskImportLinkLookupInterface::class);
        $lookup->expects(self::never())->method('findOnOtherBoards');
        $this->imported('Here', '7');

        $this->importCsv("Task ID,Task Name\n7,Here\n", lookup: $lookup);
        $result = $this->importCsv("id,title\n7,Here\n", lookup: $lookup, source: TaskImportSource::TaskBoardCsv);

        self::assertSame(1, $result->created, 'own-format ids are not ClickUp ids');
    }

    public function testOnlyLinksAnImportWroteForTheScopeMatch(): void
    {
        $task = new Task($this->board, 'Legacy', $this->user, $this->board->getColumns()->first() ?: null);
        $task->addLink(new TaskLink($task, TaskLinkType::Url, 'https://example.com', 'A link'));
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'x', 'empty', ''));
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'x', 'clickup_csv #L1', 'L1'));
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'x', 'jira_csv #L1', 'L1', importSource: 'jira'));
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'x', 'clickup_csv #L1', 'L1', importSource: 'clickup'));
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'x', 'clickup_csv #L1', 'L1', importSource: 'clickup'));
        $this->tasks[] = $task;

        $result = $this->importCsv("Task ID,Task Name\nL1,Legacy\n");

        self::assertSame(1, $result->unchanged);
        self::assertSame(0, $this->flushes);
    }

    public function testTasksTheActorCannotEditAreNeitherUpdatedNorUsedAsParentOrDependency(): void
    {
        $locked     = $this->imported('Locked', 'L');
        $open       = $this->imported('Open', 'O');
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            if ($event->getTask()->getTitle() === 'Locked') {
                $event->grant();
                $event->markReadOnly();
            }
        });
        $checker = new TaskBoardResourceAccessChecker(
            $this->createMock(TeamMemberRepositoryInterface::class),
            new NullTaskBoardTeamMembershipResolver(),
            eventDispatcher: $dispatcher,
        );

        $result = $this->importCsv(
            "Task ID,Task Name,Parent ID,Waiting On\nL,Renamed,,\nO,Open,L,L\nN,New,L,\n",
            withDependencies: true,
            accessChecker: $checker,
        );

        self::assertSame([1, 0, 1, 1], [$result->created, $result->updated, $result->unchanged, $result->skipped]);
        self::assertSame('Locked', $locked->getTitle());
        self::assertNull($open->getParent());
        self::assertCount(0, $open->getOutgoingDependencies());
        self::assertNull($this->tasks[count($this->tasks) - 1]->getParent(), 'a new row is not hung under a task the actor cannot edit');
        self::assertTrue($this->warned($result, '1 row(s) were skipped: they match tasks you cannot edit.'));
        self::assertFalse($this->warned($result, 'Locked'), 'its title is not echoed');
    }

    public function testTheAncestorWalkIsExactForDeepAndBrokenTrees(): void
    {
        $chain = [];
        for ($i = 1; $i <= 1100; ++$i) {
            $chain[$i] = $this->imported('T' . $i, 'r' . $i);
            if ($i > 1) {
                $chain[$i]->setParent($chain[$i - 1]);
            }
        }
        $result = $this->importCsv("Task ID,Task Name,Parent ID\nr1,T1,r1100\n");
        self::assertTrue($this->warned($result, 'would make it its own ancestor'));
        self::assertNull($chain[1]->getParent());

        // A loop already in the data (not through the task moved): refused instead of walking forever.
        $a = $this->imported('A', 'a');
        $b = $this->imported('B', 'b');
        $a->setParent($b);
        $b->setParent($a);
        $c    = $this->imported('C', 'c');
        $loop = $this->importCsv("Task ID,Task Name,Parent ID\nc,C,a\n");
        self::assertTrue($this->warned($loop, 'would make it its own ancestor'));
        self::assertNull($c->getParent());
    }

    public function testAFileWithTooManyRowsIsRefused(): void
    {
        $result = $this->importCsv("id,title\n1,a\n2,b\n3,c\n", source: TaskImportSource::TaskBoardCsv, maxRows: 2);

        self::assertSame(['The file has 3 rows; an import takes at most 2.'], $result->errors);
        self::assertSame([], $this->tasks);
    }

    public function testNamesResolveOnCreateAndTheOptionStatusColumnIsKeptWhenMissing(): void
    {
        $ada      = new TestUser('2', 'Ada');
        $provider = $this->createMock(TaskAssigneeProviderInterface::class);
        $provider->method('assignableUsers')->willReturn([$ada]);
        $task = $this->imported('Kept', 'K');

        $result = $this->importCsv("Task ID,Task Name,Assignees,Status\nN,New,Ada,Nowhere\nK,Kept,Ada,Nowhere\n", provider: $provider, createColumns: false);

        self::assertSame([1, 1], [$result->created, $result->updated]);
        self::assertSame($ada, $this->tasks[count($this->tasks) - 1]->getAssignee());
        self::assertSame($ada, $task->getAssignee());
        self::assertSame('To do', $task->getColumn()?->getName(), 'a status the board lacks keeps the column');
    }

    private function imported(string $title, string $id): Task
    {
        $task = new Task($this->board, $title, $this->user, $this->board->getColumns()->first() ?: null);
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'import://clickup_csv/' . $id, 'clickup_csv #' . $id, $id, importSource: 'clickup'));
        $this->tasks[] = $task;

        return $task;
    }

    private function importCsv(
        string $content,
        bool $withDependencies = false,
        ?TaskImportLinkLookupInterface $lookup = null,
        TaskImportSource $source = TaskImportSource::ClickUpCsv,
        ?TaskAssigneeProviderInterface $provider = null,
        bool $createColumns = true,
        ?TaskBoardResourceAccessChecker $accessChecker = null,
        int $maxRows = 5000,
    ): TaskImportResult {
        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->method('findByBoard')->willReturnCallback(fn (): array => $this->tasks);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $task): void {
            self::assertInstanceOf(Task::class, $task);
            $this->tasks[] = $task;
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return (new TaskImportOrchestrator(
            importers: [new ClickUpCsvImporter(), new TaskBoardCsvImporter()],
            taskRepository: $repository,
            columnManager: new BoardColumnManager($this->createMock(BoardColumnRepositoryInterface::class)),
            changeRecorder: new TaskChangeRecorder(),
            userResolver: new NullTaskImportUserResolver(),
            entityManager: $entityManager,
            dependencyManager: $withDependencies ? new TaskDependencyManager($repository, new TaskChangeRecorder()) : null,
            assigneeProvider: $provider,
            linkLookup: $lookup,
            accessChecker: $accessChecker,
            maxRows: $maxRows,
        ))->import($this->board, $source, $content, 'file.csv', $this->user, new TaskImportOptions(createMissingColumns: $createColumns));
    }

    private function warned(TaskImportResult $result, string $needle): bool
    {
        return array_filter($result->warnings, static fn (string $w): bool => str_contains($w, $needle)) !== [];
    }
}
