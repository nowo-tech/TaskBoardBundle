<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpCsvImporter;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpJsonImporter;
use Nowo\TaskBoardBundle\Import\NullTaskImportUserResolver;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Tests\Stub\RecordingLogger;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_encode;

/**
 * REQ-OBS-001: every import logs its start and its result (ids and counts, no file name, no content, no user data).
 */
final class TaskImportLoggingTest extends TestCase
{
    public function testASuccessfulImportLogsStartAndFinishWithCountsOnly(): void
    {
        $logger = new RecordingLogger();
        $user   = new TestUser('1', 'dev@example.com');
        $board  = new TaskBoard('Demo', 'demo', $user);
        $board->addColumn(new BoardColumn($board, 'To do', 0));

        $content = (string) file_get_contents(__DIR__ . '/../../Fixtures/ClickUp/sample.csv');
        $result  = $this->orchestrator($logger)->import($board, TaskImportSource::ClickUpCsv, $content, 'secret-client-list.csv', $user);

        self::assertFalse($result->hasErrors());
        self::assertSame(['info TaskBoard import started', 'info TaskBoard import finished'], $logger->lines());
        $context = $logger->records[1]['context'];
        self::assertSame('nowo_task_board', $context['bundle']);
        self::assertSame($board->getId(), $context['board_id']);
        self::assertNull($context['task_id']);
        self::assertSame('clickup_csv', $context['source']);
        self::assertSame($result->created, $context['created']);
        self::assertSame(0, $context['errors']);

        $logged = (string) json_encode($logger->records);
        self::assertStringNotContainsString('secret-client-list', $logged);
        self::assertStringNotContainsString('dev@example.com', $logged);
    }

    public function testARefusedImportIsAWarning(): void
    {
        $logger = new RecordingLogger();
        $user   = new TestUser('1', 'dev@example.com');

        $this->orchestrator($logger)->import(new TaskBoard('Demo', 'demo', $user), TaskImportSource::ClickUpJson, '{oops', 'x.json', $user);

        self::assertSame(['info TaskBoard import started', 'warning TaskBoard import refused'], $logger->lines());
        self::assertSame(1, $logger->records[1]['context']['errors']);
    }

    public function testAFailedImportIsAnErrorAndRethrows(): void
    {
        $logger = new RecordingLogger();
        $user   = new TestUser('1', 'dev@example.com');

        try {
            $this->orchestrator($logger)->import(new TaskBoard('Demo', 'demo', $user), TaskImportSource::JiraCsv, '', 'x.csv', $user);
            self::fail('The unknown source must throw.');
        } catch (InvalidArgumentException) {
        }

        self::assertSame(['info TaskBoard import started', 'error TaskBoard import failed'], $logger->lines());
        self::assertSame(InvalidArgumentException::class, $logger->records[1]['context']['exception']);
    }

    private function orchestrator(RecordingLogger $logger): TaskImportOrchestrator
    {
        $taskRepository = $this->createMock(TaskRepositoryInterface::class);
        $taskRepository->method('findByBoard')->willReturn([]);

        return new TaskImportOrchestrator(
            importers: [new ClickUpCsvImporter(), new ClickUpJsonImporter()],
            taskRepository: $taskRepository,
            columnManager: new BoardColumnManager($this->createMock(BoardColumnRepositoryInterface::class)),
            changeRecorder: new TaskChangeRecorder(),
            userResolver: new NullTaskImportUserResolver(),
            entityManager: $this->createMock(EntityManagerInterface::class),
            logger: $logger,
        );
    }
}
