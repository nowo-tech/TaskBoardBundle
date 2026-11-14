<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Folder;

use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\ColumnType;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Event\TaskStatusChangedEvent;
use Nowo\TaskBoardBundle\Exception\InvalidTaskDependencyException;
use Nowo\TaskBoardBundle\Folder\FolderStatusSets;
use Nowo\TaskBoardBundle\Folder\TaskBoardFolderSnapshot;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_filter;
use function array_map;
use function array_reverse;
use function array_values;

/**
 * Since 2.19.0, audit PW (proofs of concept turned into regressions): a column edit on a board of a folder that shares its
 * statuses needs the right on every board of the folder (PW-H1); the tasks a column change moves or completes get their
 * history rows and events (PW-H1, PW-L5); the cycle check sees the folder's other boards (PW-L3).
 */
final class SharedColumnsRightTest extends CopyTestCase
{
    /** @var list<object> */
    private array $statusEvents = [];

    protected function setUp(): void
    {
        parent::setUp();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach (['task_status_changed', 'task_reopened'] as $name) {
            $dispatcher->addListener('nowo_task_board.' . $name, function (object $event): void {
                $this->statusEvents[] = $event;
            });
        }
    }

    public function testAColumnEditorWithoutTheRightOnEveryBoardIsRefused(): void
    {
        $member                   = $this->user('member@example.com');
        [$mine, $theirs, $victim] = $this->sharingFolder($member);

        $this->signIn($member);
        $columns = $this->columns();
        $fresh   = $this->em->find(TaskBoard::class, $mine->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        foreach ([
            'update'  => static fn () => $columns->update(ColumnTypes::ordered($fresh)[0], new BoardColumnFormData('Pwned'), $member),
            'delete'  => static fn (): bool => $columns->delete(ColumnTypes::ordered($fresh)[1], $member),
            'add'     => static fn (): BoardColumn => $columns->add($fresh, new BoardColumnFormData('Extra'), $member),
            'reorder' => static fn () => $columns->reorder($fresh, array_map(static fn (BoardColumn $c): string => $c->getId(), array_reverse(ColumnTypes::ordered($fresh))), $member),
            'flag'    => static fn (): bool => $columns->setHumansOnly(ColumnTypes::ordered($fresh)[0], true, $member),
        ] as $what => $call) {
            try {
                $call();
                self::fail($what . ' should be refused');
            } catch (TaskBoardRestructureRefusedException $refused) {
                self::assertSame(TaskBoardRestructureRefusedException::NOT_ALLOWED, $refused->reason, $what);
            }
        }

        $this->clear();
        self::assertSame(['Todo', 'Doing', 'Done'], $this->names($theirs));
        self::assertSame(['Todo', 'Doing', 'Done'], $this->names($mine), 'nothing changed on his own board either');
        self::assertSame('Doing', $this->reload($victim)->getColumn()?->getName());
    }

    /** The board UI: the reorder is refused with a message, nothing changes. */
    public function testTheBoardUiRefusesWithAMessage(): void
    {
        $member          = $this->user('member@example.com');
        [$mine, $theirs] = $this->sharingFolder($member);
        $order           = array_map(static fn (BoardColumn $c): string => $c->getId(), array_reverse(ColumnTypes::ordered($mine)));
        $this->login($member);

        $base   = '/tools/task-board/board/' . $mine->getId();
        $column = ColumnTypes::ordered($mine)[1];
        $form   = static fn (string $name): array => ['board_column_form' => ['name' => $name, '_token' => PredictableCsrfTokenManager::value('board_column_form')]];
        foreach ([
            'reorder' => [$base . '/columns/reorder', ['_token' => PredictableCsrfTokenManager::value('task_board_column_reorder'), 'columnOrder' => $order]],
            'add'     => [$base . '/column', $form('Extra')],
            'update'  => [$base . '/column/' . $column->getId(), $form('Pwned')],
            'delete'  => [$base . '/column/' . $column->getId() . '/delete', ['_token' => PredictableCsrfTokenManager::value('task_board_column_delete')]],
        ] as $what => [$path, $body]) {
            $this->client->request('POST', $path, $body);
            self::assertResponseRedirects(null, null, $what);
            $crawler = $this->client->followRedirect();
            self::assertStringContainsString('shares its statuses with boards you may not manage', $crawler->text(), $what);
        }
        $this->clear();
        self::assertSame(['Todo', 'Doing', 'Done'], $this->names($mine));
        self::assertSame(['Todo', 'Doing', 'Done'], $this->names($theirs));
    }

    public function testPropagationNeverRewritesABoardTheActorMayNotManage(): void
    {
        $member          = $this->user('member@example.com');
        [$mine, $theirs] = $this->sharingFolder($member);
        $this->signIn($member);
        // An edit made around BoardColumnManager (host code): the second line holds.
        $fresh = $this->em->find(TaskBoard::class, $mine->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        ColumnTypes::ordered($fresh)[0]->setName('Pwned');
        $this->em->flush();

        $this->statusSets()->propagateFrom($mine->getId(), ['Todo' => 'Pwned'], [], $member);

        $this->clear();
        self::assertSame(['Todo', 'Doing', 'Done'], $this->names($theirs));
    }

    public function testAnAllowedEditCarriesHistoryAndEventsToTheSiblings(): void
    {
        $member                   = $this->user('member@example.com');
        [$mine, $theirs, $victim] = $this->sharingFolder($member);
        $admin                    = $this->admin();
        $this->signIn($admin);
        $this->statusEvents = [];

        $fresh = $this->em->find(TaskBoard::class, $mine->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        $this->columns()->delete(ColumnTypes::ordered($fresh)[1], $admin);

        $this->clear();
        self::assertSame(['Todo', 'Done'], $this->names($theirs));
        $moved = $this->reload($victim);
        self::assertSame('Todo', $moved->getColumn()?->getName());
        $row = $this->em->getRepository(TaskChangeHistory::class)->findOneBy(['task' => $victim->getId(), 'changeType' => TaskChangeType::Column]);
        self::assertSame(['Doing', 'Todo'], [$row?->getOldValue(), $row?->getNewValue()], 'the sibling task has its column row');
        $events = array_values(array_filter($this->statusEvents, static fn (object $e): bool => $e instanceof TaskStatusChangedEvent && $e->task->id === $victim->getId()));
        self::assertCount(1, $events);
        self::assertSame(['web', 'columns', 'Todo'], [$events[0]->via->channel, $events[0]->via->operation, $events[0]->to->name]);
    }

    /** PW-L5: a retype completes the column's tasks with a history row and TASK_COMPLETED, as Done by hand does. */
    public function testARetypeCompletesWithHistoryAndEvents(): void
    {
        $this->signIn($this->owner);
        $board = $this->board('B', ['Todo', 'Review', 'Done'], tenantKey: 'acme');
        $task  = $this->task($board, 'Open in Review', 1);
        $this->clear();
        $this->events = [];

        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        $this->columns()->update(ColumnTypes::ordered($fresh)[1], new BoardColumnFormData('Review', type: ColumnType::Done), $this->owner);

        $this->clear();
        self::assertTrue($this->reload($task)->isCompleted());
        $row = $this->em->getRepository(TaskChangeHistory::class)->findOneBy(['task' => $task->getId(), 'changeType' => TaskChangeType::Completed]);
        self::assertNotNull($row, 'a completed row');
        self::assertContains('task_completed', $this->eventNames());

        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        $this->columns()->update(ColumnTypes::ordered($fresh)[1], new BoardColumnFormData('Review', type: ColumnType::Custom), $this->owner);
        $this->clear();
        self::assertFalse($this->reload($task)->isCompleted());
        self::assertNotEmpty(array_filter($this->statusEvents, static fn (object $e): bool => !$e instanceof TaskStatusChangedEvent), 'TASK_REOPENED');
    }

    /** PW-L3: a same-board dependency that closes a cycle through another board of the folder is refused. */
    public function testACycleThroughASiblingBoardIsRefused(): void
    {
        $this->signIn($this->owner);
        $a      = $this->board('A', tenantKey: 'acme');
        $b      = $this->board('B', tenantKey: 'acme');
        $folder = $this->restructurer()->createFolder($this->owner, 'acme', 'F');
        $this->restructurer()->moveBoardToFolder($this->owner, $a->getId(), $folder->id);
        $this->restructurer()->moveBoardToFolder($this->owner, $b->getId(), $folder->id);
        $a1   = $this->task($a, 'A1');
        $a2   = $this->task($a, 'A2');
        $b1   = $this->task($b, 'B1');
        $deps = self::getContainer()->get(TaskDependencyManager::class);
        self::assertInstanceOf(TaskDependencyManager::class, $deps);
        $deps->add($a1, $b1, TaskDependencyType::Blocks, $this->owner);
        $deps->add($b1, $a2, TaskDependencyType::Blocks, $this->owner);
        $this->clear();

        $this->expectException(InvalidTaskDependencyException::class);
        $deps->add($this->reload($a2), $this->reload($a1), TaskDependencyType::Blocks, $this->owner);
    }

    /**
     * Two boards in a folder sharing the member's board's statuses; the other board is the owner's, the member may not
     * manage it.
     *
     * @return array{TaskBoard, TaskBoard, Task, TaskBoardFolderSnapshot}
     */
    private function sharingFolder(AppUser $member): array
    {
        $mine   = $this->board('Member board', ['Todo', 'Doing', 'Done'], creator: $member, tenantKey: 'acme');
        $theirs = $this->board('Private board', ['Todo', 'Doing', 'Done'], creator: $this->owner, tenantKey: 'acme');
        $victim = $this->task($theirs, 'Secret task in Doing', 1);
        $admin  = $this->admin();
        $this->signIn($admin);
        $folder = $this->restructurer()->createFolder($admin, 'acme', 'Shared');
        $this->restructurer()->moveBoardToFolder($admin, $mine->getId(), $folder->id);
        $this->restructurer()->moveBoardToFolder($admin, $theirs->getId(), $folder->id);
        $this->statusSets()->shareFrom($admin, $folder->id, $mine->getId());

        return [$mine, $theirs, $victim, $folder];
    }

    private function admin(): AppUser
    {
        $admin = $this->em->getRepository(AppUser::class)->findOneBy(['email' => 'admin@example.com']);

        return $admin instanceof AppUser ? $admin : $this->user('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
    }

    /** @return list<string> */
    private function names(TaskBoard $board): array
    {
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);

        return array_map(static fn (BoardColumn $c): string => $c->getName(), ColumnTypes::ordered($fresh));
    }

    private function signIn(object $user): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        self::assertInstanceOf(AppUser::class, $user);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function columns(): BoardColumnManager
    {
        $service = self::getContainer()->get(BoardColumnManager::class);
        self::assertInstanceOf(BoardColumnManager::class, $service);

        return $service;
    }

    private function statusSets(): FolderStatusSets
    {
        $service = self::getContainer()->get(FolderStatusSets::class);
        self::assertInstanceOf(FolderStatusSets::class, $service);

        return $service;
    }

    private function restructurer(): TaskBoardRestructureInterface
    {
        $service = self::getContainer()->get(TaskBoardRestructureInterface::class);
        self::assertInstanceOf(TaskBoardRestructureInterface::class, $service);

        return $service;
    }
}
