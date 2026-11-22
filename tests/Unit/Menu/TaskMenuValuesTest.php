<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Menu;

use Nowo\TaskBoardBundle\Bridge\TimeTrack\TimeTrackTaskTimerStarter;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Menu\TaskMenu;
use Nowo\TaskBoardBundle\Menu\TaskMenuOperation;
use Nowo\TaskBoardBundle\Menu\TaskTimerStart;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Exception\TaskNotTrackableException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

use function in_array;

/**
 * Since 2.22.0: the values of the task menu, its `action` encoding and the TimeTrack timer behind "Start timer".
 */
final class TaskMenuValuesTest extends TestCase
{
    public function testTheActionValueRoundTrips(): void
    {
        self::assertSame('t-1|status:c-2', TaskMenuOperation::value('t-1', TaskMenuOperation::STATUS, 'c-2'));
        self::assertSame('t-1|archive', TaskMenuOperation::value('t-1', TaskMenuOperation::ARCHIVE));
        self::assertSame(['t-1', 'status', 'c:2'], TaskMenuOperation::parse('t-1|status:c:2'));
        self::assertSame(['t-1', 'archive', ''], TaskMenuOperation::parse('t-1|archive'));
        self::assertSame(['nonsense', '', ''], TaskMenuOperation::parse('nonsense'));
        self::assertSame(['', '', ''], TaskMenuOperation::parse(''));
    }

    public function testAMenuWritesWhenAnyWriteEntryIsThere(): void
    {
        $task = new Task(new TaskBoard('B', 'b', null), 'T', null);
        self::assertFalse($this->menu($task)->writes());
        foreach (['assignMe', 'unassignMe', 'statuses', 'priorities', 'dueChoices', 'timer', 'archive', 'delete'] as $entry) {
            self::assertTrue($this->menu($task, [$entry => in_array($entry, ['statuses', 'priorities', 'dueChoices'], true) ? ['x'] : true])->writes(), $entry);
        }
    }

    public function testTheTimerStartsThroughTimeTrack(): void
    {
        $task    = new Task(new TaskBoard('B', 'b', null), 'T', null);
        $user    = new TestUser('1', 'ana');
        $started = $this->timers(null);
        $starter = new TimeTrackTaskTimerStarter($started);

        self::assertTrue($starter->isAvailable());
        self::assertSame(TaskTimerStart::Started, $starter->start($user, $task));
        self::assertSame([[$task->getId(), ClientType::Web]], $started->calls);
        self::assertSame(TaskTimerStart::AlreadyRunning, (new TimeTrackTaskTimerStarter($this->timers(ActiveTimerConflictException::alreadyRunning('other'))))->start($user, $task));
        self::assertSame(TaskTimerStart::Refused, (new TimeTrackTaskTimerStarter($this->timers(new TaskNotTrackableException('no'))))->start($user, $task));
        self::assertSame(TaskTimerStart::Refused, (new TimeTrackTaskTimerStarter($this->timers(new RuntimeException('locked'))))->start($user, $task));
        // Not a security user, or no TimeTrack services: nothing is started.
        self::assertSame(TaskTimerStart::Refused, $starter->start(new stdClass(), $task));
        $none = new TimeTrackTaskTimerStarter();
        self::assertFalse($none->isAvailable());
        self::assertSame(TaskTimerStart::Refused, $none->start($user, $task));
    }

    /**
     * @param array<string, mixed> $entries
     */
    private function menu(Task $task, array $entries = []): TaskMenu
    {
        return new TaskMenu(
            task: $task,
            edit: true,
            assignMe: $entries['assignMe'] ?? false,
            unassignMe: $entries['unassignMe'] ?? false,
            assignOthers: true,
            statuses: $entries['statuses'] ?? [],
            priorities: $entries['priorities'] ?? [],
            dueChoices: $entries['dueChoices'] ?? [],
            tags: true,
            timer: $entries['timer'] ?? false,
            copy: true,
            archive: $entries['archive'] ?? false,
            delete: $entries['delete'] ?? false,
        );
    }

    /**
     * TimeTrack's TimerService is final: a double with the same method.
     */
    private function timers(?Throwable $failure): object
    {
        return new class($failure) {
            /** @var list<array{string, ClientType}> */
            public array $calls = [];

            public function __construct(private readonly ?Throwable $failure)
            {
            }

            public function start(object $user, string $taskId, ClientType $clientType): stdClass
            {
                if ($this->failure instanceof Throwable) {
                    throw $this->failure;
                }
                $this->calls[] = [$taskId, $clientType];

                return new stdClass();
            }
        };
    }
}
