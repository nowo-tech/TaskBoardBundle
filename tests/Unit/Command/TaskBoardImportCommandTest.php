<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Command\TaskBoardImportCommand;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpCsvImporter;
use Nowo\TaskBoardBundle\Import\NullTaskImportUserResolver;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class TaskBoardImportCommandTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/clickup/sample.csv';

    private TaskBoard $board;

    private TestUser $user;

    protected function setUp(): void
    {
        $this->user  = new TestUser('1', 'dev@example.com');
        $this->board = new TaskBoard('Demo', 'demo', $this->user);
        $this->board->addColumn(new BoardColumn($this->board, 'To do', 0));
    }

    public function testImportsFileForAuthenticatedUser(): void
    {
        $tester = $this->tester(authenticated: true);

        self::assertSame(Command::SUCCESS, $tester->execute(['board-id' => $this->board->getId(), 'file' => self::FIXTURE]));
        self::assertStringContainsString('Imported 3 task(s), skipped 0, created 2 column(s).', $tester->getDisplay());
    }

    public function testNoCreateColumnsReportsWarnings(): void
    {
        $tester = $this->tester(authenticated: true);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'board-id'            => $this->board->getId(),
            'file'                => self::FIXTURE,
            '--no-create-columns' => true,
            '--force'             => true,
        ]));
        self::assertStringContainsString('created 0 column(s)', $tester->getDisplay());
        self::assertStringContainsString('[WARNING] Status "Done"', $tester->getDisplay());
    }

    public function testParserErrorsFail(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'tb');
        self::assertIsString($file);
        file_put_contents($file, "Task Name\n");

        try {
            $tester = $this->tester(authenticated: true);
            self::assertSame(Command::FAILURE, $tester->execute(['board-id' => $this->board->getId(), 'file' => $file]));
            self::assertStringContainsString('empty', $tester->getDisplay());
        } finally {
            unlink($file);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, bool, string}> */
    public static function failures(): iterable
    {
        yield 'unknown board' => [['board-id' => 'missing', 'file' => self::FIXTURE], true, 'Board not found.'];
        yield 'unreadable file' => [['file' => '/nonexistent/export.csv'], true, 'is not readable'];
        yield 'invalid source' => [['file' => self::FIXTURE, '--source' => 'asana'], true, 'Invalid --source value.'];
        yield 'anonymous' => [['file' => self::FIXTURE], false, 'No authenticated user found.'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('failures')]
    public function testFailures(array $input, bool $authenticated, string $message): void
    {
        $tester = $this->tester($authenticated);

        self::assertSame(Command::FAILURE, $tester->execute($input + ['board-id' => $this->board->getId()]));
        self::assertStringContainsString($message, $tester->getDisplay());
    }

    private function tester(bool $authenticated): CommandTester
    {
        $boards = $this->createMock(TaskBoardRepositoryInterface::class);
        $boards->method('findById')->willReturnCallback(fn (string $id): ?TaskBoard => $id === $this->board->getId() ? $this->board : null);

        $tasks = $this->createMock(TaskRepositoryInterface::class);
        $tasks->method('findByBoard')->willReturn([]);

        $orchestrator = new TaskImportOrchestrator(
            importers: [new ClickUpCsvImporter()],
            taskRepository: $tasks,
            columnManager: new BoardColumnManager($this->createMock(BoardColumnRepositoryInterface::class)),
            changeRecorder: new TaskChangeRecorder(),
            userResolver: new NullTaskImportUserResolver(),
            entityManager: $this->createMock(EntityManagerInterface::class),
        );

        $tokenStorage = new TokenStorage();
        if ($authenticated) {
            $tokenStorage->setToken(new UsernamePasswordToken($this->user, 'main', $this->user->getRoles()));
        }

        return new CommandTester(new TaskBoardImportCommand($boards, $orchestrator, $tokenStorage));
    }
}
