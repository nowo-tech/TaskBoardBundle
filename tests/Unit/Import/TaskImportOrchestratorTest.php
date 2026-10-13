<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpCsvImporter;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpJsonImporter;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportOptions;
use Nowo\TaskBoardBundle\Import\NullTaskImportUserResolver;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Import\Trello\TrelloJsonImporter;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TaskImportOrchestratorTest extends TestCase
{
    public function testImportsRowsAndCreatesMissingColumns(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $board->addColumn(new BoardColumn($board, 'To do', 0));

        $taskRepository = $this->createMock(TaskRepositoryInterface::class);
        $taskRepository->method('findByBoard')->willReturn([]);
        $taskRepository->expects(self::never())->method('save');

        $columnRepository = $this->createMock(BoardColumnRepositoryInterface::class);
        $columnRepository->expects(self::exactly(2))->method('save');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(3))->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $orchestrator = new TaskImportOrchestrator(
            importers: [new ClickUpCsvImporter()],
            taskRepository: $taskRepository,
            columnManager: new BoardColumnManager($columnRepository),
            changeRecorder: new TaskChangeRecorder(),
            userResolver: new NullTaskImportUserResolver(),
            entityManager: $entityManager,
        );

        $result = $orchestrator->import(
            board: $board,
            source: TaskImportSource::ClickUpCsv,
            content: (string) file_get_contents(__DIR__ . '/../../Fixtures/clickup/sample.csv'),
            filename: 'clickup.csv',
            actor: $user,
            options: new TaskImportOptions(createMissingColumns: true, skipExisting: true),
        );

        self::assertSame(3, $result->created);
        self::assertSame(0, $result->skipped);
        self::assertSame(2, $result->columnsCreated);
        self::assertFalse($result->hasErrors());
    }

    public function testSkipsExistingExternalIds(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $board->addColumn(new BoardColumn($board, 'To do', 0));

        $existing = new Task($board, 'Existing', $user);
        $existing->addLink(new TaskLink(
            task: $existing,
            linkType: TaskLinkType::Other,
            url: 'import://clickup_csv/1001',
            label: 'clickup_csv #1001',
            externalId: '1001',
        ));

        $taskRepository = $this->createMock(TaskRepositoryInterface::class);
        $taskRepository->method('findByBoard')->willReturn([$existing]);

        $columnRepository = $this->createMock(BoardColumnRepositoryInterface::class);
        $entityManager    = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $orchestrator = new TaskImportOrchestrator(
            importers: [new ClickUpCsvImporter()],
            taskRepository: $taskRepository,
            columnManager: new BoardColumnManager($columnRepository),
            changeRecorder: new TaskChangeRecorder(),
            userResolver: new NullTaskImportUserResolver(),
            entityManager: $entityManager,
        );

        $result = $orchestrator->import(
            board: $board,
            source: TaskImportSource::ClickUpCsv,
            content: (string) file_get_contents(__DIR__ . '/../../Fixtures/clickup/sample.csv'),
            filename: 'clickup.csv',
            actor: $user,
        );

        self::assertSame(2, $result->created);
        self::assertSame(1, $result->skipped);
    }

    public function testComputesPositionsOncePerImportAndIncrementsPerColumn(): void
    {
        $user   = new TestUser('1', 'dev@example.com');
        $board  = new TaskBoard('Demo', 'demo', $user);
        $column = new BoardColumn($board, 'To do', 0);
        $board->addColumn($column);

        $existing = new Task($board, 'Existing', $user, column: $column, position: 4);

        $taskRepository = $this->createMock(TaskRepositoryInterface::class);
        $taskRepository->expects(self::once())->method('findByBoard')->willReturn([$existing]);

        /** @var list<Task> $persisted */
        $persisted     = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $task) use (&$persisted): void {
            $persisted[] = $task;
        });

        $orchestrator = new TaskImportOrchestrator(
            importers: [new ClickUpCsvImporter()],
            taskRepository: $taskRepository,
            columnManager: new BoardColumnManager($this->createMock(BoardColumnRepositoryInterface::class)),
            changeRecorder: new TaskChangeRecorder(),
            userResolver: new NullTaskImportUserResolver(),
            entityManager: $entityManager,
        );

        $content = "Task ID,Task Name,Status\n\n1,First,To do\n2,Second,To do\n3,Third,Unknown\n";
        $result  = $orchestrator->import(
            board: $board,
            source: TaskImportSource::ClickUpCsv,
            content: "\r\n" . $content,
            filename: 'clickup.csv',
            actor: $user,
            options: new TaskImportOptions(createMissingColumns: false),
        );

        self::assertSame(3, $result->created);
        self::assertSame([5, 6, 7], array_map(static fn (Task $task): int => $task->getPosition(), $persisted));
    }

    public function testPositionsWithoutAnyColumnUseTheWholeBoard(): void
    {
        $user     = new TestUser('1', 'dev@example.com');
        $board    = new TaskBoard('Demo', 'demo', $user);
        $existing = new Task($board, 'Existing', $user, position: 2);

        $taskRepository = $this->createMock(TaskRepositoryInterface::class);
        $taskRepository->method('findByBoard')->willReturn([$existing]);

        /** @var list<Task> $persisted */
        $persisted     = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $task) use (&$persisted): void {
            $persisted[] = $task;
        });

        $orchestrator = new TaskImportOrchestrator(
            importers: [new ClickUpCsvImporter()],
            taskRepository: $taskRepository,
            columnManager: new BoardColumnManager($this->createMock(BoardColumnRepositoryInterface::class)),
            changeRecorder: new TaskChangeRecorder(),
            userResolver: new NullTaskImportUserResolver(),
            entityManager: $entityManager,
        );

        $orchestrator->import(
            board: $board,
            source: TaskImportSource::ClickUpCsv,
            content: "Task ID;Task Name\n1;First\n2;Second\n",
            filename: 'clickup.csv',
            actor: $user,
            options: new TaskImportOptions(createMissingColumns: false),
        );

        self::assertSame([3, 4], array_map(static fn (Task $task): int => $task->getPosition(), $persisted));
    }

    public function testFailedFlushResetsClosedEntityManager(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $board->addColumn(new BoardColumn($board, 'To do', 0));

        $taskRepository = $this->createMock(TaskRepositoryInterface::class);
        $taskRepository->method('findByBoard')->willReturn([]);

        $failure       = new RuntimeException('Deadlock');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException($failure);
        $entityManager->method('isOpen')->willReturn(false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $entityManager]);
        $registry->expects(self::once())->method('resetManager')->with('default')->willReturn($entityManager);

        $orchestrator = new TaskImportOrchestrator(
            importers: [new ClickUpCsvImporter()],
            taskRepository: $taskRepository,
            columnManager: new BoardColumnManager($this->createMock(BoardColumnRepositoryInterface::class)),
            changeRecorder: new TaskChangeRecorder(),
            userResolver: new NullTaskImportUserResolver(),
            entityManager: $entityManager,
            managerRegistry: $registry,
        );

        $this->expectExceptionObject($failure);
        $orchestrator->import(
            board: $board,
            source: TaskImportSource::ClickUpCsv,
            content: "Task ID,Task Name\n1,First\n",
            filename: 'clickup.csv',
            actor: $user,
        );
    }

    public function testParserErrorsBecomeResultErrors(): void
    {
        $user      = new TestUser('1', 'dev@example.com');
        $persisted = [];
        $result    = $this->orchestrator($persisted)->import(new TaskBoard('Demo', 'demo', $user), TaskImportSource::ClickUpJson, '{oops', 'x.json', $user);

        self::assertTrue($result->hasErrors());
        self::assertStringContainsString('Invalid ClickUp JSON', $result->errors[0]);
    }

    public function testUnknownSourceThrows(): void
    {
        $user      = new TestUser('1', 'dev@example.com');
        $persisted = [];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No importer registered for source "jira_csv"');
        $this->orchestrator($persisted)->import(new TaskBoard('Demo', 'demo', $user), TaskImportSource::JiraCsv, '', 'x.csv', $user);
    }

    public function testUrlOnlyRowsGetUrlLinkAndResolvedAssigneesBecomeMembers(): void
    {
        $user     = new TestUser('1', 'dev@example.com');
        $assignee = new TestUser('2', 'jane@example.com');
        $board    = new TaskBoard('Demo', 'demo', $user);
        $board->addColumn(new BoardColumn($board, 'To do', 0));

        $resolver = $this->createMock(TaskImportUserResolverInterface::class);
        $resolver->method('resolveByEmail')->willReturnCallback(static fn (string $email): ?object => $email === 'jane@example.com' ? $assignee : null);

        $persisted = [];
        $result    = $this->orchestrator($persisted, $resolver)->import(
            $board,
            TaskImportSource::TrelloJson,
            '{"cards":[{"name":"Linked","shortUrl":"https://trello.com/c/1"}]}',
            'b.json',
            $user,
        );
        self::assertSame(1, $result->created);
        $link = $persisted[0]->getLinks()->first();
        self::assertInstanceOf(TaskLink::class, $link);
        self::assertSame(TaskLinkType::Url, $link->getLinkType());
        self::assertSame('https://trello.com/c/1', $link->getUrl());

        $persisted = [];
        $result    = $this->orchestrator($persisted, $resolver)->import(
            $board,
            TaskImportSource::ClickUpCsv,
            "Task Name,Assignee Email\nMine,jane@example.com\nTheirs,ghost@example.com\n",
            'c.csv',
            $user,
        );
        self::assertSame(2, $result->created);
        self::assertSame($assignee, $persisted[0]->getMembers()->first()->getUser());
        self::assertCount(0, $persisted[1]->getMembers());
        self::assertStringContainsString('ghost@example.com', $result->warnings[0]);
    }

    public function testCyclicParentsAreImportedWithoutInfiniteRecursion(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);

        $persisted = [];
        $result    = $this->orchestrator($persisted)->import(
            $board,
            TaskImportSource::ClickUpCsv,
            "Task ID,Task Name,Parent ID\nA,Alpha,B\nB,Beta,A\n",
            'c.csv',
            $user,
        );

        self::assertSame(2, $result->created);
        self::assertSame(['Alpha', 'Beta'], array_map(static fn (Task $task): string => $task->getTitle(), $persisted));
    }

    /**
     * @param list<Task> $persisted
     */
    private function orchestrator(array &$persisted, ?TaskImportUserResolverInterface $resolver = null): TaskImportOrchestrator
    {
        $taskRepository = $this->createMock(TaskRepositoryInterface::class);
        $taskRepository->method('findByBoard')->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $task) use (&$persisted): void {
            $persisted[] = $task;
        });

        return new TaskImportOrchestrator(
            importers: [new ClickUpCsvImporter(), new ClickUpJsonImporter(), new TrelloJsonImporter()],
            taskRepository: $taskRepository,
            columnManager: new BoardColumnManager($this->createMock(BoardColumnRepositoryInterface::class)),
            changeRecorder: new TaskChangeRecorder(),
            userResolver: $resolver ?? new NullTaskImportUserResolver(),
            entityManager: $entityManager,
        );
    }
}
