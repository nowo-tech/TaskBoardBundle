<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\DddHost;

use DateTimeImmutable;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\ORM\Tools\SchemaTool;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardPerson;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskFilter;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskListInterface;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardViewer;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardWorkloadRow;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManagerInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardCreatorInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Nowo\TaskBoardBundle\Service\TaskCommentManagerInterface;
use Nowo\TaskBoardBundle\Service\TaskManagerInterface;
use Nowo\TaskBoardBundle\Service\TaskTimeEntrySnapshotPurger;
use Nowo\TaskBoardBundle\Service\TaskTimeLoggerInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/*
 * Since 2.15.0 (REQ-ARCH-001, both host kinds tested): the DDD host of {@see DddHostKernel} — users that are plain PHP
 * objects, XML mapping, the private entity manager, user references by id, ports through their twins only — creates a
 * board, columns, tasks, comments, members and time through the bundle's ports, and the bundle's pages show it with the
 * names the host's directory gives. The rows keep the users' ids exactly (a look-alike id in another case is another
 * user).
 */
use function json_encode;

final class DddHostKernelTest extends WebTestCase
{
    private KernelBrowser $client;

    private TaskBoardEntityManager $em;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new DddHostKernel();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = static::getContainer()->get('app.task_board_em');
        self::assertInstanceOf(TaskBoardEntityManager::class, $em);
        $this->em = $em;
        $tool     = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    public function testTheDddHostWorksThroughThePortsAndThePages(): void
    {
        $container = static::getContainer();
        $users     = DddUsers::all();
        $ana       = $users['u-ana'];
        $bruno     = $users['u-bruno'];
        $creator   = $container->get(TaskBoardCreatorInterface::class);
        $columns   = $container->get(BoardColumnManagerInterface::class);
        $tasks     = $container->get(TaskManagerInterface::class);
        $comments  = $container->get(TaskCommentManagerInterface::class);
        $time      = $container->get(TaskTimeLoggerInterface::class);
        $context   = $container->get(TaskChangeContext::class);
        self::assertInstanceOf(TaskBoardCreatorInterface::class, $creator);
        self::assertInstanceOf(BoardColumnManagerInterface::class, $columns);
        self::assertInstanceOf(TaskManagerInterface::class, $tasks);
        self::assertInstanceOf(TaskCommentManagerInterface::class, $comments);
        self::assertInstanceOf(TaskTimeLoggerInterface::class, $time);
        self::assertInstanceOf(TaskChangeContext::class, $context);

        $board = $creator->create(new TaskBoardFormData('Domain board', 'domain-board'), $ana);
        $columns->add($board, new BoardColumnFormData('Review', '#3b82f6'), $ana);
        $task = $tasks->create($board, new TaskFormData('Domain task'), $ana);
        $context->run('rule:7', static function () use ($comments, $task, $bruno): void {
            $comments->add($task, $bruno, 'Reviewed by the rule');
        });
        $time->log($task, $bruno, 45, new DateTimeImmutable('2026-11-12'));
        $task->addMember(new TaskMember($task, $bruno, TaskMemberRole::Assignee));
        $this->em->flush();
        $boardId = $board->getId();
        $taskId  = $task->getId();
        $this->em->clear();

        // The rows keep the ids, exactly.
        $repository = $container->get(TaskRepositoryInterface::class);
        self::assertInstanceOf(TaskRepositoryInterface::class, $repository);
        $loaded = $repository->findById($taskId);
        self::assertInstanceOf(Task::class, $loaded);
        self::assertEquals(new TaskBoardUserRef('u-ana'), $loaded->getCreator());
        $member = $loaded->getMembers()->first();
        self::assertInstanceOf(TaskMember::class, $member);
        self::assertEquals(new TaskBoardUserRef('u-bruno'), $member->getUser());
        $before = $loaded->getColumn()?->getId();
        $table  = $this->em->getClassMetadata(Task::class)->getTableName();
        self::assertSame('u-ana', $this->em->getConnection()->fetchOne('SELECT creator_id FROM ' . $table . ' WHERE id = ?', [$taskId]));

        // The pages, as Ana (the creator): her board, the task with Bruno's comment, time and membership by name.
        $this->client->loginUser($ana);
        $this->client->request('GET', '/tools/task-board/board/' . $boardId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Domain task');
        self::assertSelectorTextContains('body', 'Review');
        $this->client->request('GET', '/tools/task-board/task/' . $taskId);
        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Reviewed by the rule', $page);
        self::assertStringContainsString('Bruno Domain', $page, 'the directory names the comment author, the member and the time');
        self::assertStringContainsString('ddd rule 7', $page, 'the twin cause labeler');
        // The member picker offers the assignee policy's users by id: a look-alike id in another case is another user.
        self::assertStringContainsString('value="u-ana"', $page);
        self::assertStringContainsString('value="U-ANA"', $page);

        // A write over HTTP: the task moves to the next column.
        $this->client->request('POST', '/tools/task-board/task/' . $taskId . '/advance', ['_token' => PredictableCsrfTokenManager::value('task_board_task_advance'), 'action' => 'next']);
        self::assertResponseRedirects();
        $this->em->clear();
        $after = $repository->findById($taskId)?->getColumn()?->getId();

        // Lookups by user id use the id columns, exactly: Bruno's tracked tasks, the team rows of a user, the multi-board
        // list and its people (named by the directory).
        $trackable = $repository->findTrackableForUser('u-bruno', null, 10, 0);
        self::assertSame([$taskId], array_map(static fn (Task $task): string => $task->getId(), $trackable));
        // Audit OC-M1 / OC-M2: look-alike ids never match (trailing / leading space, ZWSP, NBSP, case, accent). On MySQL
        // and MariaDB (CI) the id columns are binary NO PAD; every lookup re-checks the id byte for byte in PHP.
        foreach (['u-bruno ', ' u-bruno', "u-bruno\u{200B}", "u-bruno\u{00A0}", 'U-BRUNO', 'u-brunó'] as $lookAlike) {
            self::assertSame([], $repository->findTrackableForUser($lookAlike, null, 10, 0), 'look-alike ' . json_encode($lookAlike));
        }
        $teams = $container->get(TeamMemberRepositoryInterface::class);
        self::assertInstanceOf(TeamMemberRepositoryInterface::class, $teams);
        self::assertSame([], $teams->findByUserId('u-ana'));
        self::assertFalse($teams->isManagerOf('u-ana', 'u-bruno'));
        // A look-alike id is refused before any write (a PAD SPACE collation would take it for Bruno).
        $tasks2 = $container->get(TaskManagerInterface::class);
        self::assertInstanceOf(TaskManagerInterface::class, $tasks2);
        try {
            $tasks2->create($this->repositoryBoard($boardId), new TaskFormData('Look-alike'), new DddUser('u-bruno ', 'Spaced', 'spaced@example.com'));
            self::fail('an id with a trailing space is refused');
        } catch (InvalidArgumentException|ConversionException) {
        }
        $this->em->clear();
        $purger = $container->get('app.snapshot_purger');
        self::assertInstanceOf(TaskTimeEntrySnapshotPurger::class, $purger);
        try {
            $purger->detachUser(new DddUser("u-bruno\u{200B}", 'Zwsp', 'zwsp@example.com'));
            self::fail('a look-alike id is refused at the erasure entry point');
        } catch (InvalidArgumentException) {
        }
        self::assertSame(0, $purger->detachUser(new DddUser('U-BRUNO', 'Upper', 'upper@example.com')), 'another user (case) has no rows');
        self::assertSame(1, $this->rows(TaskTimeEntry::class, 'user_id = ?', ['u-bruno']), 'Bruno keeps his time');
        DddMultiBoardScope::$boardIds = [$boardId];
        $list                         = $container->get(MultiBoardTaskListInterface::class);
        self::assertInstanceOf(MultiBoardTaskListInterface::class, $list);
        $viewer = new MultiBoardViewer('u-ana');
        self::assertCount(1, $list->boards($viewer));
        $workload = $list->workload($viewer, new MultiBoardTaskFilter());
        self::assertSame(['Bruno Domain'], array_map(static fn (MultiBoardWorkloadRow $row): string => $row->person?->label ?? '-', $workload->rows));
        self::assertSame(['u-bruno'], array_map(static fn (MultiBoardPerson $person): string => $person->id, $workload->assignees));
        self::assertSame([], $list->boards(new MultiBoardViewer('u-nobody')), 'a viewer the directory does not know sees nothing');
        DddMultiBoardScope::$boardIds = [];
        self::assertNotNull($after);
        self::assertNotSame($before, $after);
        self::assertSame(1, $this->rows(TaskBoard::class, 'slug = ?', ['domain-board']));
    }

    private function repositoryBoard(string $id): TaskBoard
    {
        $boards = static::getContainer()->get(TaskBoardRepositoryInterface::class);
        self::assertInstanceOf(TaskBoardRepositoryInterface::class, $boards);
        $board = $boards->findById($id);
        self::assertInstanceOf(TaskBoard::class, $board);

        return $board;
    }

    /**
     * @param class-string $class
     * @param list<mixed> $parameters
     */
    private function rows(string $class, string $where, array $parameters): int
    {
        $table = $this->em->getClassMetadata($class)->getTableName();

        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where, $parameters);
    }
}
