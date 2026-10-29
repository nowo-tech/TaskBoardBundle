<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Exception\InvalidTaskDependencyException;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 1.15: adding and removing dependencies, with the self, other board, duplicate and cycle refusals.
 */
final class TaskDependencyManagerTest extends TestCase
{
    private TaskBoard $board;

    private object $user;

    /** @var list<Task> */
    private array $tasks = [];

    private int $saves = 0;

    protected function setUp(): void
    {
        $this->user  = new stdClass();
        $this->board = new TaskBoard('Board', 'board', $this->user);
    }

    public function testAddsADependencyToBothTasksAndRecordsIt(): void
    {
        [$a, $b] = $this->tasks('A', 'B');

        $dependency = $this->manager()->add($a, $b, TaskDependencyType::Blocks, $this->user);

        self::assertSame($a, $dependency->getSourceTask());
        self::assertTrue($a->getOutgoingDependencies()->contains($dependency));
        self::assertTrue($b->getIncomingDependencies()->contains($dependency));
        self::assertSame(1, $this->saves);
        $entry = $a->getChangeHistory()->last();
        self::assertNotFalse($entry);
        self::assertSame(TaskChangeType::DependencyAdded, $entry->getChangeType());
        self::assertSame('B', $entry->getNewValue());
        self::assertSame('blocks', $entry->getContext());
    }

    public function testRefusesADependencyOnItself(): void
    {
        [$a] = $this->tasks('A');

        $this->assertRefused(InvalidTaskDependencyException::REASON_SELF, fn (): mixed => $this->manager()->add($a, $a, TaskDependencyType::Blocks, $this->user));
    }

    public function testRefusesATaskOfAnotherBoard(): void
    {
        [$a]     = $this->tasks('A');
        $foreign = new Task(new TaskBoard('Other', 'other', $this->user), 'Foreign', $this->user);

        $this->assertRefused(InvalidTaskDependencyException::REASON_OTHER_BOARD, fn (): mixed => $this->manager()->add($a, $foreign, TaskDependencyType::Related, $this->user));
    }

    /** @return iterable<string, array{TaskDependencyType, TaskDependencyType, bool}> */
    public static function duplicates(): iterable
    {
        yield 'same blocks' => [TaskDependencyType::Blocks, TaskDependencyType::Blocks, false];
        yield 'blocked by from the other side' => [TaskDependencyType::Blocks, TaskDependencyType::BlockedBy, true];
        yield 'related either way' => [TaskDependencyType::Related, TaskDependencyType::Related, true];
        yield 'same duplicates' => [TaskDependencyType::Duplicates, TaskDependencyType::Duplicates, false];
    }

    #[DataProvider('duplicates')]
    public function testRefusesTheSameLinkTwice(TaskDependencyType $first, TaskDependencyType $second, bool $fromTheOtherTask): void
    {
        [$a, $b] = $this->tasks('A', 'B');
        $manager = $this->manager();
        $manager->add($a, $b, $first, $this->user);

        $this->assertRefused(InvalidTaskDependencyException::REASON_DUPLICATE, fn (): mixed => $fromTheOtherTask ? $manager->add($b, $a, $second, $this->user) : $manager->add($a, $b, $second, $this->user));
    }

    public function testDuplicatesInTheOtherDirectionIsAnotherLink(): void
    {
        [$a, $b] = $this->tasks('A', 'B');
        $manager = $this->manager();
        $manager->add($a, $b, TaskDependencyType::Duplicates, $this->user);
        $manager->add($b, $a, TaskDependencyType::Duplicates, $this->user);
        $manager->add($a, $b, TaskDependencyType::Related, $this->user);

        self::assertCount(2, $a->getOutgoingDependencies());
    }

    public function testRefusesADirectCycle(): void
    {
        [$a, $b] = $this->tasks('A', 'B');
        $manager = $this->manager();
        $manager->add($a, $b, TaskDependencyType::Blocks, $this->user);

        $this->assertRefused(InvalidTaskDependencyException::REASON_CYCLE, fn (): mixed => $manager->add($a, $b, TaskDependencyType::BlockedBy, $this->user));
    }

    public function testRefusesALongerCycleButAcceptsAChain(): void
    {
        [$a, $b, $c, $d, $e] = $this->tasks('A', 'B', 'C', 'D', 'E');
        $manager             = $this->manager();
        $manager->add($a, $b, TaskDependencyType::Blocks, $this->user);
        $manager->add($c, $b, TaskDependencyType::BlockedBy, $this->user);
        // A diamond and a related link are no cycle.
        $manager->add($a, $d, TaskDependencyType::Blocks, $this->user);
        $manager->add($d, $c, TaskDependencyType::Blocks, $this->user);
        $manager->add($c, $a, TaskDependencyType::Related, $this->user);
        // E blocks A: the search from A walks the diamond (C reached twice) without finding E.
        $manager->add($e, $a, TaskDependencyType::Blocks, $this->user);

        $this->assertRefused(InvalidTaskDependencyException::REASON_CYCLE, fn (): mixed => $manager->add($c, $a, TaskDependencyType::Blocks, $this->user));
    }

    public function testRemovesAnOutgoingOrIncomingDependency(): void
    {
        [$a, $b, $c] = $this->tasks('A', 'B', 'C');
        $manager     = $this->manager();
        $outgoing    = $manager->add($a, $b, TaskDependencyType::Blocks, $this->user);
        $incoming    = $manager->add($c, $a, TaskDependencyType::Duplicates, $this->user);

        self::assertFalse($manager->remove($a, 'missing', $this->user));
        self::assertTrue($manager->remove($a, $outgoing->getId(), $this->user));
        self::assertTrue($b->getIncomingDependencies()->isEmpty());
        $entry = $a->getChangeHistory()->last();
        self::assertNotFalse($entry);
        self::assertSame(TaskChangeType::DependencyRemoved, $entry->getChangeType());
        self::assertSame('blocks', $entry->getContext());

        self::assertTrue($manager->remove($a, $incoming->getId(), $this->user));
        self::assertTrue($c->getOutgoingDependencies()->isEmpty());
        $entry = $a->getChangeHistory()->last();
        self::assertNotFalse($entry);
        self::assertSame('duplicated_by', $entry->getContext());
        self::assertSame('C', $entry->getOldValue());
    }

    public function testRelationReadsFromEitherTask(): void
    {
        self::assertSame(['blocks', 'blocked_by'], [TaskDependencyType::Blocks->relation(), TaskDependencyType::Blocks->relation(true)]);
        self::assertSame(['blocked_by', 'blocks'], [TaskDependencyType::BlockedBy->relation(), TaskDependencyType::BlockedBy->relation(true)]);
        self::assertSame(['related', 'related'], [TaskDependencyType::Related->relation(), TaskDependencyType::Related->relation(true)]);
        self::assertSame(['duplicates', 'duplicated_by'], [TaskDependencyType::Duplicates->relation(), TaskDependencyType::Duplicates->relation(true)]);
    }

    private function assertRefused(string $reason, callable $add): void
    {
        try {
            $add();
            self::fail('InvalidTaskDependencyException expected');
        } catch (InvalidTaskDependencyException $exception) {
            self::assertSame($reason, $exception->reason);
            self::assertNotSame('', $exception->getMessage());
        }
    }

    /** @return list<Task> */
    private function tasks(string ...$titles): array
    {
        foreach ($titles as $title) {
            $this->tasks[] = new Task($this->board, $title, $this->user);
        }

        return $this->tasks;
    }

    private function manager(): TaskDependencyManager
    {
        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->method('findByBoard')->willReturnCallback(fn (): array => $this->tasks);
        $repository->method('save')->willReturnCallback(function (): void {
            ++$this->saves;
        });

        return new TaskDependencyManager($repository, new TaskChangeRecorder());
    }
}
