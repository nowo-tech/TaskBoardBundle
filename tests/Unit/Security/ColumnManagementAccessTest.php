<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Security;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Since 2.5.0 (TB-13): managing the columns is its own BOARD_ACCESS_CHECK decision, `manage_columns`, granted by default
 * to whoever may edit the board; a host deny sticks.
 */
final class ColumnManagementAccessTest extends TestCase
{
    private TestUser $owner;

    private TestUser $stranger;

    private TaskBoard $board;

    protected function setUp(): void
    {
        $this->owner    = new TestUser('1', 'owner@example.com');
        $this->stranger = new TestUser('3', 'stranger@example.com');
        $this->board    = new TaskBoard('Board', 'board', $this->owner);
    }

    public function testByDefaultTheEditDecisionDecides(): void
    {
        $dispatcher = new EventDispatcher();
        $actions    = [];
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use (&$actions): void {
            $actions[] = $event->getAction();
        });
        $checker = $this->checker($dispatcher);

        self::assertTrue($checker->canManageColumns($this->owner, $this->board));
        self::assertFalse($checker->canManageColumns($this->stranger, $this->board));
        self::assertContains(BoardAccessCheckEvent::ACTION_MANAGE_COLUMNS, $actions);
        self::assertSame('manage_columns', BoardAccessCheckEvent::ACTION_MANAGE_COLUMNS);
        self::assertTrue($this->checker(null)->canManageColumns($this->owner, $this->board));
    }

    public function testAReadOnlyBoardHasNoColumnManagementEvenWithAGrant(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_VIEW) {
                $event->markReadOnly();
            }
            $event->grant();
        });

        self::assertFalse($this->checker($dispatcher)->canManageColumns($this->owner, $this->board));
    }

    public function testAHostDenyRefusesItAndSticks(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_MANAGE_COLUMNS) {
                $event->deny();
            }
        }, 10);
        // A later, broader listener that grants every action does not lift it.
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            $event->grant();
        }, -10);
        $checker = $this->checker($dispatcher);

        self::assertFalse($checker->canManageColumns($this->owner, $this->board));
        self::assertTrue($checker->canEditBoard($this->owner, $this->board), 'the tasks stay editable');
    }

    /** Audit IM-I2: read-only on the column decision refuses it. */
    public function testAReadOnlyMarkOnTheColumnDecisionRefusesIt(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_MANAGE_COLUMNS) {
                $event->grant();
                $event->markReadOnly();
            }
        });
        $checker = $this->checker($dispatcher);

        self::assertFalse($checker->canManageColumns($this->owner, $this->board));
        self::assertTrue($checker->canEditBoard($this->owner, $this->board));
    }

    public function testOnlyTheColumnDecisionKeepsItsDeny(): void
    {
        $columns = new BoardAccessCheckEvent($this->owner, $this->board, true, BoardAccessCheckEvent::ACTION_MANAGE_COLUMNS);
        $columns->deny();
        $columns->grant();
        self::assertTrue($columns->isDenied());
        self::assertFalse($columns->isGranted());

        $view = new BoardAccessCheckEvent($this->owner, $this->board, true);
        $view->deny();
        $view->grant();
        self::assertFalse($view->isDenied(), 'the last call wins for the other actions, as before');
        self::assertTrue($view->isGranted());
    }

    private function checker(?EventDispatcher $dispatcher): TaskBoardResourceAccessChecker
    {
        $repository = $this->createMock(TeamMemberRepositoryInterface::class);
        $repository->method('findByUserId')->willReturn([]);

        return new TaskBoardResourceAccessChecker($repository, new NullTaskBoardTeamMembershipResolver(), null, $dispatcher);
    }
}
