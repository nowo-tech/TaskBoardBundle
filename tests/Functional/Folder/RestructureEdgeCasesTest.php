<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Folder;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Folder\TaskBoardDependencyScope;
use Nowo\TaskBoardBundle\Folder\TaskBoardFolderManager;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use RuntimeException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function str_repeat;

/**
 * Since 2.17.0: the refusals and edges of the folder manager and the restructure service.
 */
final class RestructureEdgeCasesTest extends CopyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $tokens = static::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
    }

    public function testFolderManagerEdges(): void
    {
        $manager = $this->manager();
        self::assertNull($manager->find(''));
        self::assertNull($manager->find(str_repeat('a', 37)));
        $this->refused(TaskBoardRestructureRefusedException::INVALID, fn () => $manager->create($this->owner, 'acme', '  '));
        $this->refused(TaskBoardRestructureRefusedException::INVALID, fn () => $manager->create($this->owner, 'acme', 'Big', str_repeat('x', TaskBoardFolderManager::DESCRIPTION_MAX + 1)));

        $folder = $manager->create($this->owner, null, 'No tenant');
        self::assertSame([$folder->getId()], array_map(static fn (TaskBoardFolder $f): string => $f->getId(), $manager->folders(null)));
        $board = $this->board('Board');
        $manager->moveBoard($this->owner, $board, $folder);
        $manager->moveBoard($this->owner, $board, $folder);
        self::assertSame([$board->getId()], $manager->boardIds($folder));
        self::assertCount(1, $manager->boardsIn($folder), 'an active board is listed');

        $board->archive();
        $this->em->flush();
        self::assertSame([], $manager->boardsIn($folder));
        self::assertSame([$board->getId()], $manager->boardIds($folder), 'archived ones count for "empty"');

        $stranger = $this->user('stranger@example.com', []);
        $this->signIn($stranger);
        $this->refused(TaskBoardRestructureRefusedException::NOT_ALLOWED, static fn () => $manager->update($stranger, $folder, 'Renamed', null, 0));
        $this->refused(TaskBoardRestructureRefusedException::NOT_ALLOWED, static fn () => $manager->delete($stranger, $folder));
    }

    public function testAFailedFlushLeavesTheManagerOpen(): void
    {
        $manager = $this->manager();
        $this->em->getEventManager()->addEventListener('onFlush', new class {
            public function onFlush(): void
            {
                throw new RuntimeException('database down');
            }
        });
        try {
            $manager->create($this->owner, 'acme', 'Epic');
            self::fail('expected the failure');
        } catch (RuntimeException $failure) {
            self::assertSame('database down', $failure->getMessage());
        }
        $em = static::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertTrue($em->isOpen());
    }

    public function testMoveRefusalsAndNoOps(): void
    {
        $service  = $this->restructurer();
        $source   = $this->board('Source', tenantKey: 'acme');
        $target   = $this->board('Target', tenantKey: 'acme');
        $parent   = $this->task($source, 'Parent');
        $child    = $this->task($source, 'Child', 0, $parent);
        $onTarget = $this->task($target, 'Already there');
        $parent->getDocuments()->add(new TaskDocument($parent, 'Notes', 'Kept', $this->owner));
        $this->em->flush();

        $this->refused(TaskBoardRestructureRefusedException::NOT_FOUND, fn () => $service->moveTasks($this->owner, [$parent->getId()], ''));
        $this->refused(TaskBoardRestructureRefusedException::NOT_FOUND, fn () => $service->moveTasks($this->owner, [''], $target->getId()));
        $this->refused(TaskBoardRestructureRefusedException::INVALID, fn () => $service->moveTasks($this->owner, [$parent->getId()], $target->getId(), ['To do' => 7]));

        $noop = $service->moveTasks($this->owner, [$onTarget->getId()], $target->getId());
        self::assertSame([$onTarget->getId()], $noop->unchangedTaskIds);
        self::assertSame([], $noop->movedTaskIds);

        $this->quota()->deny = [TaskBoardQuotaCheckEvent::ACTION_TASK_MOVE];
        $refusal             = $this->refused(TaskBoardRestructureRefusedException::QUOTA, fn () => $service->moveTasks($this->owner, [$parent->getId()], $target->getId()));
        self::assertSame('Your plan is full.', $refusal->hostMessage());
        $this->quota()->deny = [];

        $this->clear();
        $moved = $service->moveTasks($this->owner, [$child->getId(), $parent->getId()], $target->getId());
        self::assertSame([$parent->getId(), $child->getId()], $moved->movedTaskIds, 'the child asked for moves with its parent, once');

        $target = $this->em->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $target);
        $target->archive();
        $this->em->flush();
        $this->refused(TaskBoardRestructureRefusedException::BOARD_ARCHIVED, fn () => $service->moveTasks($this->owner, [$parent->getId()], $target->getId()));
        $this->refused(TaskBoardRestructureRefusedException::NOT_FOUND, fn () => $service->updateFolder($this->owner, 'unknown', 'Name', null, 0));
        $this->refused(TaskBoardRestructureRefusedException::NOT_FOUND, fn () => $service->deleteFolder($this->owner, 'unknown'));
    }

    public function testAMemberTheTargetCannotAssignIsRemovedWithHistory(): void
    {
        self::$taskBoardConfig = ['assignee_provider' => 'app.assignee_provider'];
        self::ensureKernelShutdown();
        $this->setUp();
        $source = $this->board('Source', tenantKey: 'acme');
        $target = $this->board('Target', creator: $this->user('ana@example.com'), tenantKey: 'acme');
        $task   = $this->task($source, 'Task');
        $task->addMember(new TaskMember($task, $this->owner, TaskMemberRole::Assignee));
        $this->em->flush();
        $this->clear();
        // The default policy needs canManageBoard() on the target: a manage role.
        $admin = $this->user('admin@example.com', ['ROLE_ADMIN']);
        $this->signIn($admin);

        $result = $this->restructurer()->moveTasks($admin, [$task->getId()], $target->getId());

        self::assertCount(1, $result->removedMemberIds);
        $this->clear();
        self::assertCount(0, $this->reload($task)->getMembers());
        self::assertSame(1, $this->em->getRepository(TaskChangeHistory::class)->count(['task' => $task->getId(), 'changeType' => TaskChangeType::MemberRemoved]));
    }

    public function testRefusalMessageAndScopeEdges(): void
    {
        $plain = new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_FOUND);
        self::assertNull($plain->hostMessage());

        $scope = new TaskBoardDependencyScope();
        $one   = $this->board('One', tenantKey: 'acme');
        $two   = $this->board('Two', tenantKey: 'acme');
        self::assertTrue($scope->allows($one, $one));
        self::assertFalse($scope->allows($one, $two));

        $folder = new TaskBoardFolder('Other tenant', 'beta');
        $this->expectException(InvalidArgumentException::class);
        $one->changeFolder($folder);
    }

    private function manager(): TaskBoardFolderManager
    {
        $service = static::getContainer()->get(TaskBoardFolderManager::class);
        self::assertInstanceOf(TaskBoardFolderManager::class, $service);

        return $service;
    }

    private function restructurer(): TaskBoardRestructureInterface
    {
        $service = static::getContainer()->get(TaskBoardRestructureInterface::class);
        self::assertInstanceOf(TaskBoardRestructureInterface::class, $service);

        return $service;
    }

    private function signIn(object $user): void
    {
        $tokens = static::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function refused(string $reason, callable $call): TaskBoardRestructureRefusedException
    {
        try {
            $call();
        } catch (TaskBoardRestructureRefusedException $exception) {
            self::assertSame($reason, $exception->reason);

            return $exception;
        }
        self::fail('Expected the refusal ' . $reason);
    }
}
