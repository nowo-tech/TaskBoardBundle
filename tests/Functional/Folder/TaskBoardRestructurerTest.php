<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Folder;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Folder\TaskBoardFolderSnapshot;
use Nowo\TaskBoardBundle\Folder\TaskBoardMoveResult;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Since 2.18.0 (Beacon spec 234, FR-031 / FR-045): folders and task moves through the restructure service.
 */
final class TaskBoardRestructurerTest extends CopyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The default policy asks the create-board roles of the signed-in user, as a console command run as a person does.
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
    }

    public function testWithoutASignedInUserTheDefaultPolicyRefusesFolders(): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(null);
        $this->assertRestructureRefused(TaskBoardRestructureRefusedException::NOT_ALLOWED, fn (): TaskBoardFolderSnapshot => $this->restructurer()->createFolder($this->owner, 'acme', 'Epic'));
        self::assertSame(0, $this->em->getRepository(TaskBoardFolder::class)->count([]));
    }

    public function testFoldersAreCreatedListedUpdatedAndDeletedOnlyWhenEmpty(): void
    {
        $service = $this->restructurer();
        $second  = $service->createFolder($this->owner, 'acme', 'Integrar Sanitas', "## Epic\nBody");
        $first   = $service->createFolder($this->owner, 'acme', 'Entidad Promotion', null, 0);
        $service->createFolder($this->owner, 'other', 'Not mine');

        self::assertSame(0, $second->position, 'the first folder of the tenant');
        self::assertSame(1, $service->createFolder($this->owner, 'acme', 'Third')->position, 'after the last folder of the tenant');
        self::assertSame(['Entidad Promotion', 'Integrar Sanitas', 'Third'], array_map(static fn (TaskBoardFolderSnapshot $f): string => $f->name, $service->folders('acme')));
        self::assertSame("## Epic\nBody", $service->folder($second->id)?->description);

        $updated = $service->updateFolder($this->owner, $first->id, 'Entidad', 'Desc', 5);
        self::assertSame(['Entidad', 'Desc', 5], [$updated->name, $updated->description, $updated->position]);

        $board = $this->board('Story', tenantKey: 'acme');
        $service->moveBoardToFolder($this->owner, $board->getId(), $second->id);
        self::assertSame([$board->getId()], $service->folder($second->id)?->boardIds);
        $this->assertRestructureRefused(TaskBoardRestructureRefusedException::NOT_EMPTY, fn () => $service->deleteFolder($this->owner, $second->id));

        $service->moveBoardToFolder($this->owner, $board->getId(), null);
        $service->deleteFolder($this->owner, $second->id);
        self::assertNull($service->folder($second->id));
        self::assertTrue($this->em->isOpen());
    }

    public function testNothingCrossesTenants(): void
    {
        $service = $this->restructurer();
        $foreign = $service->createFolder($this->owner, 'other', 'Theirs');
        $board   = $this->board('Mine', tenantKey: 'acme');
        $theirs  = $this->board('Theirs', tenantKey: 'other');
        // A look-alike tenant key is another tenant (REQ-SEC-006).
        $alike = $this->board('Alike', tenantKey: 'ACME');
        $task  = $this->task($theirs, 'Their task');

        $this->assertRestructureRefused(TaskBoardRestructureRefusedException::NOT_FOUND, fn () => $service->moveBoardToFolder($this->owner, $board->getId(), $foreign->id));
        $this->assertRestructureRefused(TaskBoardRestructureRefusedException::NOT_FOUND, fn (): TaskBoardMoveResult => $service->moveTasks($this->owner, [$task->getId()], $board->getId()));
        $this->assertRestructureRefused(TaskBoardRestructureRefusedException::NOT_FOUND, fn (): TaskBoardMoveResult => $service->moveTasks($this->owner, [$task->getId()], $alike->getId()));
        $this->assertRestructureRefused(TaskBoardRestructureRefusedException::NOT_FOUND, fn (): TaskBoardMoveResult => $service->moveTasks($this->owner, ['missing'], $board->getId()));
        $this->assertRestructureRefused(TaskBoardRestructureRefusedException::NOT_FOUND, fn () => $service->moveBoardToFolder($this->owner, 'missing', null));
        self::assertSame([], $service->folders('ACME'));

        $this->clear();
        self::assertSame($theirs->getId(), $this->reload($task)->getBoard()->getId());
        self::assertNull($this->em->find(TaskBoard::class, $board->getId())?->getFolderId());
    }

    public function testTheManageRightIsAsked(): void
    {
        $service  = $this->restructurer();
        $stranger = $this->user('stranger@example.com');
        $board    = $this->board('Owned', tenantKey: 'acme');
        $target   = $this->board('Target', tenantKey: 'acme');
        $task     = $this->task($board, 'Task');
        $folder   = $service->createFolder($this->owner, 'acme', 'Folder');

        $this->assertRestructureRefused(TaskBoardRestructureRefusedException::NOT_ALLOWED, static fn () => $service->moveBoardToFolder($stranger, $board->getId(), $folder->id));
        $this->assertRestructureRefused(TaskBoardRestructureRefusedException::NOT_ALLOWED, static fn (): TaskBoardMoveResult => $service->moveTasks($stranger, [$task->getId()], $target->getId()));
    }

    public function testAMoveKeepsIdNumberCommentsTimeTagsAndHistory(): void
    {
        $source                                          = $this->board('Pilot', ['Pendiente', 'En ejecución', 'Hecho', 'Cancelada'], tenantKey: 'acme');
        $target                                          = $this->board('Story', ['Pendiente', 'En ejecución', 'Hecho', 'Cancelada'], tenantKey: 'acme');
        [$story, $child, $grandchild, $archived, $other] = $this->richTask($source);
        $sibling                                         = $this->task($source, 'Second subtask', 1, $story);
        $this->clear();
        $child  = $this->reload($child);
        $before = [
            'number'  => $child->getNumber(),
            'tags'    => $this->reload($story)->getTags(),
            'history' => $this->em->getRepository(TaskChangeHistory::class)->count(['task' => $child->getId()]),
            'time'    => $this->reload($story)->getTimeEntries()->count(),
        ];
        $this->em->persist(new TaskComment($child, $this->owner, 'Comment on the subtask'));
        $this->em->flush();
        $this->clear();
        $this->events = [];

        $result = $this->restructurer()->moveTasks($this->owner, [$child->getId(), $sibling->getId(), $grandchild->getId()], $target->getId());

        self::assertSame([$child->getId(), $grandchild->getId(), $sibling->getId()], $result->movedTaskIds, 'the grandchild moves with its parent, once');
        self::assertSame([$child->getId() => $story->getId(), $grandchild->getId() => $child->getId(), $sibling->getId() => $story->getId()], $result->formerParents);
        $moveRow = $this->em->getRepository(TaskChangeHistory::class)->findOneBy(['task' => $child->getId(), 'changeType' => TaskChangeType::BoardMoved]);
        self::assertSame('parent:' . $story->getId(), $moveRow?->getContext());
        $this->clear();
        $moved = $this->reload($child);
        self::assertSame($target->getId(), $moved->getBoard()->getId());
        self::assertNull($moved->getParent(), 'a subtask asked for becomes a top-level task');
        self::assertSame($moved->getId(), $this->reload($grandchild)->getParent()?->getId(), 'its own subtask stays under it');
        self::assertTrue($moved->isCompleted());
        self::assertSame('En ejecución', $moved->getColumn()?->getName(), 'column by name');
        self::assertSame('Pendiente', $this->reload($grandchild)->getColumn()?->getName());
        self::assertSame($before['number'], $moved->getNumber());
        self::assertSame(1, $this->em->getRepository(TaskComment::class)->count(['task' => $moved->getId()]));
        self::assertSame($before['history'] + 1, $this->em->getRepository(TaskChangeHistory::class)->count(['task' => $moved->getId()]));
        self::assertSame(1, $this->em->getRepository(TaskChangeHistory::class)->count(['task' => $moved->getId(), 'changeType' => TaskChangeType::BoardMoved]));
        // The story stays where it was with its own time and tags.
        $story = $this->reload($story);
        self::assertSame($source->getId(), $story->getBoard()->getId());
        self::assertSame($before['tags'], $story->getTags());
        self::assertSame($before['time'], $story->getTimeEntries()->count());
        self::assertSame(['task_board_changed', 'task_board_changed'], array_values(array_filter($this->eventNames(), static fn (string $name): bool => $name === 'task_board_changed')));
        self::assertTrue($this->em->isOpen());
    }

    public function testTheColumnMapNamesColumnsByIdOrNameElseTheFirstOpenColumn(): void
    {
        $source = $this->board('Pilot', ['Open', 'Review', 'Done'], tenantKey: 'acme');
        $target = $this->board('Story', ['Backlog', 'Check', 'Closed'], tenantKey: 'acme');
        $a      = $this->task($source, 'A', 0);
        $b      = $this->task($source, 'B', 1);
        $c      = $this->task($source, 'C', 2);
        $c->markCompleted();
        $this->em->flush();
        $check = $target->getColumns()->get(1);
        self::assertNotNull($check);
        $review = $source->getColumns()->get(1);
        self::assertNotNull($review);

        $result = $this->restructurer()->moveTasks($this->owner, [$a->getId(), $b->getId(), $c->getId()], $target->getId(), [$review->getId() => 'check']);

        self::assertSame($target->getColumns()->get(0)?->getId(), $result->columns[$a->getId()], 'no map, no name: the first open column');
        self::assertSame($check->getId(), $result->columns[$b->getId()], 'mapped by source id to a target name');
        self::assertSame($target->getColumns()->get(2)?->getId(), $result->columns[$c->getId()], 'a completed task: the last column');

        $this->assertRestructureRefused(TaskBoardRestructureRefusedException::INVALID, fn (): TaskBoardMoveResult => $this->restructurer()->moveTasks($this->owner, [$a->getId()], $source->getId(), ['Backlog' => 'nowhere']));
    }

    public function testADependencyStaysWithinAFolderAndGoesOtherwise(): void
    {
        $service = $this->restructurer();
        $folder  = $service->createFolder($this->owner, 'acme', 'Epic');
        $source  = $this->board('Pilot', tenantKey: 'acme');
        $inside  = $this->board('Story 1', tenantKey: 'acme');
        $outside = $this->board('Elsewhere', tenantKey: 'acme');
        $service->moveBoardToFolder($this->owner, $source->getId(), $folder->id);
        $service->moveBoardToFolder($this->owner, $inside->getId(), $folder->id);
        $this->clear();
        $source = $this->em->find(TaskBoard::class, $source->getId());
        self::assertInstanceOf(TaskBoard::class, $source);
        $a = $this->task($source, 'A');
        $b = $this->task($source, 'B');
        $c = $this->task($source, 'C');
        $a->addOutgoingDependency(new TaskDependency($a, $b, TaskDependencyType::Blocks));
        $c->addOutgoingDependency(new TaskDependency($c, $b, TaskDependencyType::Blocks));
        $this->em->flush();

        $kept = $service->moveTasks($this->owner, [$a->getId()], $inside->getId());
        self::assertSame([], $kept->removedDependencyIds, 'same folder: kept across boards');
        $gone = $service->moveTasks($this->owner, [$c->getId()], $outside->getId());
        self::assertCount(1, $gone->removedDependencyIds);
        $this->clear();
        self::assertSame(1, $this->em->getRepository(TaskDependency::class)->count([]));
    }

    public function testAFailedMoveRollsBackAndLeavesTheEntityManagerOpen(): void
    {
        $source = $this->board('Pilot', tenantKey: 'acme');
        $target = $this->board('Story', tenantKey: 'acme');
        $task   = $this->task($source, 'A');
        $this->clear();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $failing = static function (): never {
            throw new RuntimeException('flush listener failed');
        };
        $this->em->getEventManager()->addEventListener('onFlush', new class($failing) {
            public function __construct(private $failing)
            {
            }

            public function onFlush(): void
            {
                ($this->failing)();
            }
        });
        $this->events = [];

        try {
            $this->restructurer()->moveTasks($this->owner, [$task->getId()], $target->getId());
            self::fail('expected the failure');
        } catch (RuntimeException $failure) {
            self::assertSame('flush listener failed', $failure->getMessage());
        }

        $em = self::getContainer()->get('doctrine')->getManager();
        self::assertTrue($em->isOpen(), 'the closed manager was reset (worker mode, kernel reset off)');
        self::assertSame($source->getId(), $em->getConnection()->fetchOne('SELECT board_id FROM task_board_tasks WHERE id = ?', [$task->getId()]));
        self::assertNotContains('task_board_changed', $this->eventNames(), 'no event for a rolled-back move');
        self::assertFalse($em->getConnection()->isTransactionActive());
    }

    private function restructurer(): TaskBoardRestructureInterface
    {
        $service = self::getContainer()->get(TaskBoardRestructureInterface::class);
        self::assertInstanceOf(TaskBoardRestructureInterface::class, $service);

        return $service;
    }

    private function assertRestructureRefused(string $reason, callable $call): void
    {
        try {
            $call();
        } catch (TaskBoardRestructureRefusedException $exception) {
            self::assertSame($reason, $exception->reason);

            return;
        }
        self::fail('Expected the refusal ' . $reason);
    }
}
