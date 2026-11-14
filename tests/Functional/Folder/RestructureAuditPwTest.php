<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Folder;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Since 2.19.0, audit PW (proofs of concept turned into regressions): a move never changes whether a task is completed
 * (PW-M1), and a tree that changed between its read and its locks is refused, not split across boards (PW-L2).
 */
final class RestructureAuditPwTest extends CopyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
    }

    /** PW-M1 on an untyped board: the same name is the target's last (done) column, so an open task goes elsewhere. */
    public function testAnOpenTaskNeverLandsInTheDoneColumnByName(): void
    {
        $source = $this->board('Source', ['Todo', 'Review', 'Done'], tenantKey: 'acme');
        $target = $this->board('Target', ['Todo', 'Review'], tenantKey: 'acme');
        $task   = $this->task($source, 'Open in Review', 1);

        $result = $this->restructurer()->moveTasks($this->owner, [$task->getId()], $target->getId());

        $this->clear();
        $moved = $this->reload($task);
        self::assertSame('Todo', $moved->getColumn()?->getName());
        self::assertFalse($moved->isCompleted());
        self::assertSame($moved->getColumn()?->getId(), $result->columns[$task->getId()]);
    }

    /** PW-M1: a column map that would cross done-ness is not used, either way. */
    public function testAColumnMapNeverChangesCompletion(): void
    {
        $source = $this->board('Source', ['Todo', 'Doing', 'Done'], tenantKey: 'acme');
        $target = $this->board('Target', ['Backlog', 'Doing', 'Shipped'], tenantKey: 'acme');
        $open   = $this->task($source, 'Open', 0);
        $done   = $this->task($source, 'Done', 2);
        $done->markCompleted();
        $this->em->flush();

        $this->restructurer()->moveTasks($this->owner, [$open->getId(), $done->getId()], $target->getId(), ['Todo' => 'Shipped', 'Done' => 'Doing']);

        $this->clear();
        self::assertSame(['Backlog', false], [$this->reload($open)->getColumn()?->getName(), $this->reload($open)->isCompleted()]);
        self::assertSame(['Shipped', true], [$this->reload($done)->getColumn()?->getName(), $this->reload($done)->isCompleted()]);
    }

    /** PW-M1: a map that fits is still followed. */
    public function testAFittingMapIsFollowed(): void
    {
        $source = $this->board('Source', ['Todo', 'Doing', 'Done'], tenantKey: 'acme');
        $target = $this->board('Target', ['Backlog', 'Doing', 'Shipped'], tenantKey: 'acme');
        $open   = $this->task($source, 'Open', 0);

        $this->restructurer()->moveTasks($this->owner, [$open->getId()], $target->getId(), ['Todo' => 'Doing']);

        $this->clear();
        self::assertSame('Doing', $this->reload($open)->getColumn()?->getName());
    }

    /** PW-L2: a subtask committed by another request after the tree was read is never left behind: `stale`, then a retry. */
    public function testATreeThatChangedBeforeTheLockIsRefusedStale(): void
    {
        $source = $this->board('Source', tenantKey: 'acme');
        $target = $this->board('Target', tenantKey: 'acme');
        $root   = $this->task($source, 'Root');
        $this->clear();
        $root = $this->reload($root);
        self::assertSame(0, $root->getSubtasks()->count(), 'the tree as the move reads it');

        // Another request commits a subtask in between: a row the loaded collection does not know.
        $connection = $this->em->getConnection();
        $template   = $connection->fetchAssociative('SELECT * FROM task_board_tasks WHERE id = ?', [$root->getId()]);
        self::assertIsArray($template);
        $lateId = '00000000-0000-4000-8000-00000000c0de';
        $connection->insert('task_board_tasks', ['id' => $lateId, 'parent_id' => $root->getId(), 'title' => 'Late child', 'number' => null] + $template);

        try {
            $this->restructurer()->moveTasks($this->owner, [$root->getId()], $target->getId());
            self::fail('the tree changed');
        } catch (TaskBoardRestructureRefusedException $refused) {
            self::assertSame(TaskBoardRestructureRefusedException::STALE, $refused->reason);
        }
        self::assertTrue($this->em->isOpen());
        $this->clear();
        self::assertSame($source->getId(), $this->reload($root)->getBoard()->getId(), 'nothing moved');

        $result = $this->restructurer()->moveTasks($this->owner, [$root->getId()], $target->getId());
        self::assertSame([$root->getId(), $lateId], $result->movedTaskIds, 'a retry moves the whole tree');
        $this->clear();
        $late = $this->em->find(Task::class, $lateId);
        self::assertSame($target->getId(), $late?->getBoard()->getId());
        self::assertInstanceOf(BoardColumn::class, $late?->getColumn());
        self::assertSame($target->getId(), $late->getColumn()->getBoard()->getId());
        self::assertInstanceOf(TaskBoard::class, $this->em->find(TaskBoard::class, $source->getId()));
    }

    private function restructurer(): TaskBoardRestructureInterface
    {
        $service = self::getContainer()->get(TaskBoardRestructureInterface::class);
        self::assertInstanceOf(TaskBoardRestructureInterface::class, $service);

        return $service;
    }
}
