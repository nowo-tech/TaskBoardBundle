<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\PrivateEntityManager;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardCreatorInterface;
use Nowo\TaskBoardBundle\Service\TaskCommentManagerInterface;
use Nowo\TaskBoardBundle\Service\TaskManagerInterface;
use Nowo\TaskBoardBundle\Service\TaskTimeLoggerInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 6, `database.private_entity_manager: true`): TaskBoard writes go through the bundle's
 * private manager. A change the application left pending in its own manager is never flushed by them, the security user
 * (managed by the application's manager) is adopted as a reference, and a failing TaskBoard write closes only the private
 * manager, which is created again on the next call.
 */
final class PrivateEntityManagerKernelTest extends FunctionalTestCase
{
    private EntityManagerInterface $host;

    protected function setUp(): void
    {
        self::$taskBoardConfig = ['database' => ['private_entity_manager' => true]];
        parent::setUp();
        $host = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        $this->host = $host;
    }

    public function testTaskBoardWritesNeverFlushAPendingApplicationChange(): void
    {
        $container = self::getContainer();
        $owner     = $this->host->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        $pending = new AppUser('pending@example.com');
        $this->host->persist($pending);

        $creator  = $container->get(TaskBoardCreatorInterface::class);
        $tasks    = $container->get(TaskManagerInterface::class);
        $comments = $container->get(TaskCommentManagerInterface::class);
        $time     = $container->get(TaskTimeLoggerInterface::class);
        self::assertInstanceOf(TaskBoardCreatorInterface::class, $creator);
        self::assertInstanceOf(TaskManagerInterface::class, $tasks);
        self::assertInstanceOf(TaskCommentManagerInterface::class, $comments);
        self::assertInstanceOf(TaskTimeLoggerInterface::class, $time);

        $board   = $creator->create(new TaskBoardFormData('Private', 'private'), $owner);
        $task    = $tasks->create($board, new TaskFormData('Ship it'), $owner);
        $comment = $comments->add($task, $owner, 'Looks good');
        $time->log($task, $owner, 30, new DateTimeImmutable('2026-11-12'));
        $tasks->update($task, new TaskFormData('Ship it now'), $owner);

        self::assertTrue($this->host->getUnitOfWork()->isScheduledForInsert($pending), 'the application change is still pending');
        self::assertSame(0, $this->rows(AppUser::class, 'email = ?', ['pending@example.com']), 'never flushed by TaskBoard');
        self::assertSame(1, $this->rows(TaskBoard::class, 'slug = ?', ['private']));
        self::assertSame(1, $this->rows(Task::class, 'title = ? AND creator_id = ?', ['Ship it now', $owner->getId()]), 'the security user was adopted as a reference');
        self::assertSame(1, $this->rows(TaskComment::class, 'id = ?', [$comment->getId()]));
        self::assertFalse($this->host->contains($task), 'TaskBoard rows live in the private manager');

        $this->host->flush();
        self::assertSame(1, $this->rows(AppUser::class, 'email = ?', ['pending@example.com']), 'the application flushes its own change');
    }

    public function testANewRowTheFlushCascadesGetsItsHostUserAdoptedToo(): void
    {
        $private = $this->privateManager();
        $owner   = $this->host->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        $board = new TaskBoard('Cascade', 'cascade', null);
        $task  = new Task($board, 'Cascaded member', null);
        $private->persist($board);
        $private->persist($task);
        $private->flush();

        // The member is only reached through Task::$members (cascade persist), with the application's user on it.
        $task->addMember(new TaskMember($task, $owner, TaskMemberRole::Assignee));
        $private->flush();

        self::assertSame(1, $this->rows(TaskMember::class, 'task_id = ? AND user_id = ?', [$task->getId(), $owner->getId()]));
        self::assertTrue($this->host->contains($owner), 'the application keeps its own user object');
    }

    public function testAFailingTaskBoardWriteClosesOnlyThePrivateManager(): void
    {
        $private = $this->privateManager();
        $board   = new TaskBoard('First', 'same-slug', null);
        $board->setTenantKey('acme');
        $private->persist($board);
        $private->flush();
        $twin = new TaskBoard('Second', 'same-slug', null);
        $twin->setTenantKey('acme');
        $private->persist($twin);

        try {
            $private->flush();
            self::fail('the unique index refuses the second board');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertTrue($this->host->isOpen(), 'the application manager stays open');
        $boards = self::getContainer()->get(TaskBoardRepositoryInterface::class);
        self::assertInstanceOf(TaskBoardRepositoryInterface::class, $boards);
        self::assertSame('First', $boards->findById($board->getId())?->getName(), 'a new private manager serves the next call');
    }

    private function privateManager(): TaskBoardEntityManager
    {
        $private = self::getContainer()->get('app.task_board_em');
        self::assertInstanceOf(TaskBoardEntityManager::class, $private);

        return $private;
    }

    /**
     * @param class-string $class
     * @param list<mixed> $parameters
     */
    private function rows(string $class, string $where, array $parameters): int
    {
        $table = $this->host->getClassMetadata($class)->getTableName();

        return (int) $this->host->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where, $parameters);
    }
}
