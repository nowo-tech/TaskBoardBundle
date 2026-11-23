<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\PrivateEntityManager;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Service\TaskBoardCreatorInterface;
use Nowo\TaskBoardBundle\Service\TaskCommentManagerInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\HostProject;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;

/**
 * Since 3.0.0 (REQ-ARCH-001 rule 6, audit lessons XS / W1-2): the private manager is the only mode, so both directions
 * between the application's manager and TaskBoard's are checked on a real database (SQLite and the CI servers), with a
 * relation-mapped host user and host entities that point at TaskBoard rows.
 *
 * - An application user on a TaskBoard row is written as TaskBoard's reference to the same id (an initialised instance or
 *   an uninitialised proxy); one not saved yet fails before anything is written, and the
 *   application keeps its scheduled insert.
 * - A TaskBoard row the application's manager holds is re-resolved by id on a new TaskBoard row, and refused when it is
 *   persisted (it would be inserted again).
 * - An application entity that references a TaskBoard row through `getReference()` is flushed by the application.
 * - TaskBoard never flushes the application's manager: a change the application left pending stays pending.
 */
final class HostEntitiesAcrossManagersTest extends FunctionalTestCase
{
    private EntityManagerInterface $host;

    protected function setUp(): void
    {
        parent::setUp();
        $host = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        $this->host = $host;
    }

    public function testAnInitialisedHostUserIsWrittenByIdAndStaysTheApplicationsOwn(): void
    {
        $owner = $this->host->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        $owner->setDisplayName('Changed by the application');

        $board = $this->creator()->create(new TaskBoardFormData('Held', 'held'), $owner);

        self::assertSame(1, $this->rows(TaskBoard::class, 'id = ? AND creator_id = ?', [$board->getId(), $owner->getId()]));
        self::assertTrue($this->host->contains($owner), 'the application keeps its own user object');
        self::assertNull($this->connectionValue('SELECT display_name FROM ' . $this->table(AppUser::class) . ' WHERE id = ?', [$owner->getId()]), 'TaskBoard never flushed the application\'s pending change');
        $this->host->flush();
        self::assertSame('Changed by the application', $this->connectionValue('SELECT display_name FROM ' . $this->table(AppUser::class) . ' WHERE id = ?', [$owner->getId()]));
    }

    public function testAnUninitialisedHostUserProxyIsWrittenByIdWithoutBeingLoaded(): void
    {
        $proxy = $this->host->getReference(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $proxy);
        self::assertTrue($this->host->getUnitOfWork()->isUninitializedObject($proxy));

        $board = $this->creator()->create(new TaskBoardFormData('Proxy', 'proxy'), $proxy);

        self::assertSame(1, $this->rows(TaskBoard::class, 'id = ? AND creator_id = ?', [$board->getId(), $this->owner->getId()]));
        self::assertTrue($this->host->contains($proxy), 'the application keeps its own proxy');
    }

    public function testAHostUserNotFlushedYetFailsBeforeAnythingIsWritten(): void
    {
        $pending = new AppUser('pending@example.com');
        $this->host->persist($pending);

        try {
            $this->creator()->create(new TaskBoardFormData('Pending', 'pending'), $pending);
            self::fail('an unsaved user is refused');
        } catch (LogicException $failure) {
            self::assertStringContainsString('is not saved yet', $failure->getMessage());
            self::assertStringContainsString('Flush it first', $failure->getMessage());
        }

        self::assertSame(0, $this->rows(TaskBoard::class, 'slug = ?', ['pending']), 'nothing written');
        self::assertTrue($this->host->getUnitOfWork()->isScheduledForInsert($pending), 'the application keeps its scheduled insert');
        self::assertSame(0, $this->rows(AppUser::class, 'email = ?', ['pending@example.com']));
    }

    public function testATransientUserFailsClearly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no identifier');

        $this->creator()->create(new TaskBoardFormData('Transient', 'transient'), new AppUser('nobody@example.com'));
    }

    public function testAnObjectOfTheWrongClassOnAToOneFailsClearly(): void
    {
        $project = new HostProject();
        $this->host->persist($project);
        $this->host->flush();
        $board = new TaskBoard('Wrong', 'wrong', null);
        $this->privateManager()->getClassMetadata(TaskBoard::class)->setFieldValue($board, 'creator', $project);
        $this->privateManager()->persist($board);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('expects a ' . AppUser::class);

        $this->privateManager()->flush();
    }

    public function testATaskTheApplicationLoadedIsReResolvedOnANewTaskBoardRow(): void
    {
        $task     = $this->task($this->board(), 'Loaded by the host');
        $hostTask = $this->host->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $hostTask);
        self::assertNotSame($task, $hostTask);
        $owner = $this->host->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);

        $comments = self::getContainer()->get(TaskCommentManagerInterface::class);
        self::assertInstanceOf(TaskCommentManagerInterface::class, $comments);
        $comment = $comments->add($hostTask, $owner, 'Through the host instance');

        self::assertSame(1, $this->rows(TaskComment::class, 'id = ? AND task_id = ?', [$comment->getId(), $task->getId()]));
        self::assertSame(1, $this->rows(Task::class, 'title = ?', ['Loaded by the host']), 'no duplicate task');
    }

    public function testPersistingATaskTheApplicationHoldsIsRefused(): void
    {
        $task     = $this->task($this->board(), 'Held by the host');
        $hostTask = $this->host->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $hostTask);

        try {
            $this->privateManager()->persist($hostTask);
            self::fail('a TaskBoard row the application holds is not inserted again');
        } catch (LogicException $failure) {
            self::assertStringContainsString('was loaded through the application\'s entity manager "default"', $failure->getMessage());
        }
        self::assertSame(1, $this->rows(Task::class, 'title = ?', ['Held by the host']));
    }

    public function testAnApplicationEntityReferencingATaskByIdIsFlushedByTheApplication(): void
    {
        $task          = $this->task($this->board(), 'Referenced');
        $project       = new HostProject();
        $project->task = $this->host->getReference(Task::class, $task->getId());
        $this->host->persist($project);
        $this->host->flush();

        self::assertSame(1, $this->rows(HostProject::class, 'id = ? AND task_id = ?', [$project->id, $task->getId()]));
    }

    private function creator(): TaskBoardCreatorInterface
    {
        $creator = self::getContainer()->get(TaskBoardCreatorInterface::class);
        self::assertInstanceOf(TaskBoardCreatorInterface::class, $creator);

        return $creator;
    }

    private function privateManager(): TaskBoardEntityManager
    {
        $private = self::getContainer()->get('app.task_board_em');
        self::assertInstanceOf(TaskBoardEntityManager::class, $private);

        return $private;
    }

    /**
     * @param class-string $class
     */
    private function table(string $class): string
    {
        return $this->host->getClassMetadata($class)->getTableName();
    }

    /**
     * @param list<mixed> $parameters
     */
    private function connectionValue(string $sql, array $parameters): mixed
    {
        $value = $this->host->getConnection()->fetchOne($sql, $parameters);

        return $value === false ? null : $value;
    }

    /**
     * @param class-string $class
     * @param list<mixed> $parameters
     */
    private function rows(string $class, string $where, array $parameters): int
    {
        return (int) $this->host->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->table($class) . ' WHERE ' . $where, $parameters);
    }
}
