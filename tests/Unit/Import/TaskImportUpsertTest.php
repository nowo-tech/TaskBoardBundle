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
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
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
        self::assertTrue($this->warned($result, '6 row(s) were already imported into another board and were skipped (an import never moves or copies tasks between boards): a, b, c, d, e, …'));
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

    public function testPreviousLinksAreAdoptedAndWrittenEvenWhenTheRowIsUnchanged(): void
    {
        $task = new Task($this->board, 'Legacy', $this->user, $this->board->getColumns()->first() ?: null);
        $task->addLink(new TaskLink($task, TaskLinkType::Url, 'https://example.com', 'A link'));
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'x', 'empty', ''));
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'x', 'jira_csv #L1', 'L1'));
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'x', 'clickup_csv #L1', 'L1'));
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'x', 'clickup_csv #L1', 'L1', importSource: 'clickup'));
        $this->tasks[] = $task;

        $result = $this->importCsv("Task ID,Task Name\nL1,Legacy\n");

        self::assertSame(1, $result->unchanged);
        self::assertSame(1, $this->flushes);
        self::assertSame([null, null, null, 'clickup', 'clickup'], array_values(array_map(static fn (TaskLink $link): ?string => $link->getImportSource(), [...$task->getLinks()])));
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
        ))->import($this->board, $source, $content, 'file.csv', $this->user, new TaskImportOptions(createMissingColumns: $createColumns));
    }

    private function warned(TaskImportResult $result, string $needle): bool
    {
        return array_filter($result->warnings, static fn (string $w): bool => str_contains($w, $needle)) !== [];
    }
}
