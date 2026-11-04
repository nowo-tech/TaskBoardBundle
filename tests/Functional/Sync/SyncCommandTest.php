<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Command\TaskBoardSyncCommand;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Exception\RateLimitedException;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function json_decode;

/**
 * Since 1.23: `nowo:task-board:sync <boardId> [--full] [--dry-run]` and its exit codes.
 */
final class SyncCommandTest extends SyncFunctionalTestCase
{
    public function testTheCommandSyncsALinkedBoardAndReportsWithExitCodes(): void
    {
        $tester = $this->tester();
        self::assertSame(TaskBoardSyncCommand::EXIT_INVALID, $tester->execute(['boardId' => 'nope']));

        $board = $this->board();
        self::assertSame(TaskBoardSyncCommand::EXIT_INVALID, $tester->execute(['boardId' => $board->getId()]));
        self::assertStringContainsString('not linked', $tester->getDisplay());

        $this->put($this->remote('c1', 'One', 1));
        $this->sync($board);
        $this->put($this->remote('c1', 'One changed', 2), $this->remote('c2', 'Two', 3));

        self::assertSame(Command::SUCCESS, $tester->execute(['boardId' => $board->getId(), '--dry-run' => true]));
        self::assertStringContainsString('dry run', $tester->getDisplay());
        self::assertStringNotContainsString('pk_test_token', $tester->getDisplay());
        self::assertStringContainsString('…', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['boardId' => $board->getId(), '--format' => 'json', '--full' => true, '--restart' => true]));
        $json = json_decode($tester->getDisplay(), true);
        self::assertIsArray($json);
        self::assertSame([1, 1], [$json['created'], $json['updated']]);

        $this->provider->failNext(new RateLimitedException(90));
        self::assertSame(TaskBoardSyncCommand::EXIT_PAUSED, $tester->execute(['boardId' => $board->getId()]));
        self::assertStringContainsString('wait 90 s', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['boardId' => $board->getId()]));

        $this->provider->failNext(new ProviderException(ProviderErrorCode::ServerError));
        self::assertSame(Command::FAILURE, $tester->execute(['boardId' => $board->getId()]));
        self::assertStringContainsString('The provider failed', $tester->getDisplay());

        $this->put($this->remote('c3', 'Three', 4));
        TestSyncLimitChecker::$refuseTasks = true;
        self::assertSame(TaskBoardSyncCommand::EXIT_REFUSED, $tester->execute(['boardId' => $board->getId()]));
        TestSyncLimitChecker::$refuseTasks = false;

        $this->em->createQuery('UPDATE ' . TaskBoardConnection::class . " c SET c.runningRunId = 'x', c.heartbeatAt = :now")->setParameter('now', new DateTimeImmutable(), 'datetime_immutable')->execute();
        self::assertSame(TaskBoardSyncCommand::EXIT_BUSY, $tester->execute(['boardId' => $board->getId(), '--restart' => true]));
        $this->em->createQuery('UPDATE ' . TaskBoardConnection::class . ' c SET c.runningRunId = NULL, c.createdBy = NULL')->execute();
        $this->em->clear();
        self::assertSame(TaskBoardSyncCommand::EXIT_INVALID, $tester->execute(['boardId' => $board->getId()]));
        self::assertStringContainsString('No user', $tester->getDisplay());
    }

    public function testConflictsAndWarningsArePrinted(): void
    {
        $board = $this->board();
        $this->put($this->remote('w1', 'Original', 1));
        $this->sync($board);
        $task = $this->taskTitled('Original');
        $task->setTitle('Here');
        $this->em->flush();
        $this->put($this->remote('w1', 'There', 2));
        $this->provider->removeTask(self::LIST, 'zz');
        $this->put($this->remote('w2', 'Warned', 3, email: 'nobody@example.com'));

        $tester = $this->tester();
        $tester->execute(['boardId' => $board->getId()]);
        self::assertStringContainsString('conflict on "Here" (w1): title', $tester->getDisplay());
        self::assertStringContainsString('[WARNING]', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find('nowo:task-board:sync'));
    }
}
