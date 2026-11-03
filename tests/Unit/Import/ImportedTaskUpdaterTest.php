<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\ImportAssigneeResolver;
use Nowo\TaskBoardBundle\Import\ImportedTaskUpdater;
use Nowo\TaskBoardBundle\Import\NullTaskImportUserResolver;
use Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;

/** Since 1.22: re-import precedence (source wins where it has a value) and assignee resolution by name. */
final class ImportedTaskUpdaterTest extends TestCase
{
    private TestUser $actor;

    private TaskBoard $board;

    /** @var list<BoardColumn> */
    private array $columns;

    protected function setUp(): void
    {
        $this->actor   = new TestUser('1', 'actor');
        $this->board   = new TaskBoard('Demo', 'demo', $this->actor);
        $this->columns = [new BoardColumn($this->board, 'To do', 0), new BoardColumn($this->board, 'Done', 1)];
    }

    public function testEveryFieldTheSourceHasWinsAndIsRecorded(): void
    {
        $task = new Task($this->board, 'Old', $this->actor, $this->columns[0], description: 'Old text', priority: TaskPriority::Low, estimatedMinutes: 10, tags: ['a']);
        $row  = new ImportedTaskDto(
            title: 'New',
            status: 'Done',
            priority: TaskPriority::High,
            description: 'New text',
            dueAt: new DateTimeImmutable('2026-11-10'),
            estimatedMinutes: 60,
            tags: ['b', ' a ', 'b'],
            startAt: new DateTimeImmutable('2026-11-01'),
        );

        self::assertTrue($this->apply($task, $row, $this->columns[1]));

        self::assertSame(['New', 'New text', TaskPriority::High, 'Done', 7, '2026-11-01', '2026-11-10', 60, ['a', 'b']], [
            $task->getTitle(),
            $task->getDescription(),
            $task->getPriority(),
            $task->getColumn()?->getName(),
            $task->getPosition(),
            $task->getStartAt()?->format('Y-m-d'),
            $task->getDueAt()?->format('Y-m-d'),
            $task->getEstimatedMinutes(),
            $task->getTags(),
        ]);
        self::assertTrue($task->isCompleted());
        self::assertSame(
            [TaskChangeType::Title, TaskChangeType::Description, TaskChangeType::Priority, TaskChangeType::Column, TaskChangeType::Completed, TaskChangeType::StartDate, TaskChangeType::DueDate, TaskChangeType::EstimatedMinutes, TaskChangeType::Tags],
            array_map(static fn (TaskChangeHistory $entry): TaskChangeType => $entry->getChangeType(), [...$task->getChangeHistory()]),
        );
    }

    public function testEmptyFieldsKeepTheTaskAndTheSameValuesChangeNothing(): void
    {
        $task = new Task($this->board, 'Same', $this->actor, $this->columns[0], description: 'Kept', priority: TaskPriority::Urgent, estimatedMinutes: 30, dueAt: new DateTimeImmutable('2026-11-10 00:00:00'), tags: ['x']);
        $task->setStartAt(new DateTimeImmutable('2026-11-01'));
        $updatedAt = $task->getUpdatedAt();

        self::assertFalse($this->apply($task, new ImportedTaskDto(title: 'Same', priority: TaskPriority::Low, hasPriority: false), null));
        self::assertFalse($this->apply($task, new ImportedTaskDto(
            title: 'Same',
            status: 'To do',
            priority: TaskPriority::Urgent,
            description: 'Kept',
            dueAt: new DateTimeImmutable('2026-11-10T00:00:00+05:00'),
            estimatedMinutes: 30,
            tags: ['x'],
            startAt: new DateTimeImmutable('2026-11-01'),
        ), $this->columns[0]));

        self::assertSame(TaskPriority::Urgent, $task->getPriority());
        self::assertSame($updatedAt, $task->getUpdatedAt());
        self::assertCount(0, $task->getChangeHistory());
    }

    public function testLeavingTheLastColumnReopensATaskAndColumnsWithoutALastOneOnlyMove(): void
    {
        $task = new Task($this->board, 'Done one', $this->actor, $this->columns[1]);
        $task->markCompleted();

        self::assertTrue($this->apply($task, new ImportedTaskDto(title: 'Done one'), $this->columns[0]));
        self::assertFalse($task->isCompleted());

        $open = new Task($this->board, 'Open', $this->actor, $this->columns[0]);
        self::assertTrue((new ImportedTaskUpdater())->apply($open, new ImportedTaskDto(title: 'Open'), $this->columns[1], static fn (): int => 0, [], $this->actor));
        self::assertFalse($open->isCompleted(), 'no column list: nothing to complete');
    }

    public function testTheSourceAssigneesReplaceTheTaskAssigneesOnly(): void
    {
        $ada     = new TestUser('2', 'ada');
        $bob     = new TestUser('3', 'bob');
        $watcher = new TestUser('4', 'watcher');
        $task    = new Task($this->board, 'Team', $this->actor);
        $task->addMember(new TaskMember($task, $bob, TaskMemberRole::Assignee));
        $task->addMember(new TaskMember($task, $ada, TaskMemberRole::Assignee));
        $task->addMember(new TaskMember($task, $watcher, TaskMemberRole::Watcher));
        $updater = new ImportedTaskUpdater();

        self::assertFalse($updater->applyAssignees($task, [], $this->actor));
        self::assertFalse($updater->applyAssignees($task, [$bob, $ada], $this->actor));
        self::assertTrue($updater->applyAssignees($task, [$ada, $this->actor], $this->actor));

        $members = array_map(static fn (TaskMember $member): string => $member->getUser()->getUserIdentifier() . ':' . $member->getMemberRole()->value, [...$task->getMembers()]);
        self::assertSame(['ada:assignee', 'watcher:watcher', 'actor:assignee'], array_values($members));
        self::assertSame(
            [TaskChangeType::MemberRemoved, TaskChangeType::MemberAdded],
            array_map(static fn (TaskChangeHistory $entry): TaskChangeType => $entry->getChangeType(), [...$task->getChangeHistory()]),
        );
    }

    public function testAssigneesResolveByEmailAndByUnambiguousDisplayName(): void
    {
        $ada      = new TestUser('2', 'Ada  Lovelace');
        $twinA    = new TestUser('3', 'Sam');
        $twinB    = new TestUser('4', 'sam');
        $provider = $this->createMock(TaskAssigneeProviderInterface::class);
        $provider->expects(self::once())->method('assignableUsers')->with($this->board)->willReturn([$ada, $twinA, $twinB]);
        $emails = $this->createMock(TaskImportUserResolverInterface::class);
        $provider->method('canBeAssigned')->willReturnCallback(static fn (TaskBoard $board, object $user): bool => $user === $ada);
        $emails->method('resolveByEmail')->willReturnCallback(static fn (string $email): ?object => match ($email) {
            'ada@example.com' => $ada,
            'sam@example.com' => $twinA,
            default           => null,
        });
        $resolver = new ImportAssigneeResolver($emails, $provider, $this->board);

        self::assertSame([$ada], $resolver->resolve(new ImportedTaskDto(title: 'A', assigneeEmail: 'ada@example.com', assigneeNames: ['ada lovelace'])));
        self::assertSame([$ada], $resolver->resolve(new ImportedTaskDto(title: 'B', assigneeNames: ['Sam', 'Nobody', ' ADA LOVELACE '])));
        self::assertSame([], $resolver->resolve(new ImportedTaskDto(title: 'C', assigneeEmail: 'ghost@example.com', assigneeNames: ['Sam'])));
        self::assertSame([], $resolver->resolve(new ImportedTaskDto(title: 'D')));
        self::assertSame([], $resolver->resolve(new ImportedTaskDto(title: 'E', assigneeEmail: 'sam@example.com')), 'known but not assignable on the board');

        self::assertSame([
            'Assignee "ghost@example.com" for "C" cannot be assigned on this board.',
            'Assignee "sam@example.com" for "E" cannot be assigned on this board.',
            'Assignee "Sam" (2 row(s)) does not match exactly one assignable user by display name; those tasks keep their assignees.',
            'Assignee "Nobody" (1 row(s)) does not match exactly one assignable user by display name; those tasks keep their assignees.',
        ], $resolver->warnings());
    }

    public function testNamesNeedAnAssigneeProvider(): void
    {
        $resolver = new ImportAssigneeResolver(new NullTaskImportUserResolver(), null, $this->board);

        self::assertSame([], $resolver->resolve(new ImportedTaskDto(title: 'A', assigneeNames: ['Ada'])));
        self::assertStringContainsString('no assignee provider is configured', $resolver->warnings()[0]);
    }

    private function apply(Task $task, ImportedTaskDto $row, ?BoardColumn $column): bool
    {
        return (new ImportedTaskUpdater())->apply($task, $row, $column, static fn (): int => 7, $this->columns, $this->actor);
    }
}
