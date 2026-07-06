<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpCsvImporter;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportOptions;
use Nowo\TaskBoardBundle\Import\NullTaskImportUserResolver;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

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

        $columnRepository = $this->createMock(\Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface::class);
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

        $existing = new \Nowo\TaskBoardBundle\Entity\Task($board, 'Existing', $user);
        $existing->addLink(new \Nowo\TaskBoardBundle\Entity\TaskLink(
            task: $existing,
            linkType: \Nowo\TaskBoardBundle\Enum\TaskLinkType::Other,
            url: 'import://clickup_csv/1001',
            label: 'clickup_csv #1001',
            externalId: '1001',
        ));

        $taskRepository = $this->createMock(TaskRepositoryInterface::class);
        $taskRepository->method('findByBoard')->willReturn([$existing]);

        $columnRepository = $this->createMock(\Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface::class);
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
}
