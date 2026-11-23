<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\HostPorts;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Dto\BoardCriteria;
use Nowo\TaskBoardBundle\Dto\TaskCriteria;
use Nowo\TaskBoardBundle\Enum\TaskSort;
use Nowo\TaskBoardBundle\Event\BoardListQueryEvent;
use Nowo\TaskBoardBundle\Event\ColumnCreatedEvent;
use Nowo\TaskBoardBundle\Event\ColumnDeleteCheckEvent;
use Nowo\TaskBoardBundle\Exception\TaskChangedException;
use Nowo\TaskBoardBundle\ValueObject\UserRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_map;
use function range;

/**
 * Since 2.29.0: the values of the id-based host ports (UserRef, criteria) and the id getters of the three hook events.
 */
final class HostPortValuesTest extends TestCase
{
    public function testUserRef(): void
    {
        $ref = UserRef::ofId('u-1');
        self::assertSame(['u-1', 'u-1', 'u-1'], [$ref->id, $ref->getId(), (string) $ref]);
        self::assertTrue($ref->equals(new UserRef('u-1')));
        self::assertFalse($ref->equals(new UserRef('U-1')));
        self::assertSame($ref, UserRef::of($ref));
        self::assertSame('7', UserRef::of(new class {
            public function getId(): int
            {
                return 7;
            }
        })->id);
        self::assertFalse(UserRef::isValid(' u'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function badIds(): iterable
    {
        yield 'empty' => [''];
        yield 'edge space' => ['u '];
        yield 'zero width' => ["u\u{200B}1"];
    }

    #[DataProvider('badIds')]
    public function testUserRefRefusesIdsThatCannotBeMatchedExactly(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UserRef($id);
    }

    public function testAUserWithoutAnIdHasNoReference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UserRef::of(new stdClass());
    }

    public function testTaskCriteria(): void
    {
        $criteria = TaskCriteria::forTenants(['acme', 'acme', ' bad', 'globex']);
        self::assertSame(['acme', 'globex'], $criteria->tenantKeys);
        self::assertFalse($criteria->isEmpty());
        $from = new DateTimeImmutable('2026-01-01');
        $to   = new DateTimeImmutable('2026-02-01');
        $next = $criteria->withCompleted(true)->withArchived(null)->dueBetween($from, $to)->inColumn('c')->createdBy('u')->topLevelOnly()->sortedBy(TaskSort::DueAsc)->page(10, 20);
        self::assertSame([true, null, $from, $to, 'c', 'u', true, TaskSort::DueAsc, 10, 20], [$next->completed, $next->archived, $next->dueFrom, $next->dueTo, $next->columnId, $next->creatorId, $next->topLevelOnly, $next->sort, $next->offset, $next->limit]);
        self::assertNull($next->inColumn('')->columnId);
        self::assertNull($next->createdBy('')->creatorId);
        self::assertSame(['b1'], TaskCriteria::forBoards(['b1', '', 'b1'])->boardIds);
        self::assertTrue(TaskCriteria::forBoards([''])->isEmpty());
        self::assertFalse($criteria->archived, 'active tasks by default');
    }

    /**
     * @return iterable<string, array{0: callable(): mixed}>
     */
    public static function badCriteria(): iterable
    {
        yield 'negative offset' => [static fn (): TaskCriteria => TaskCriteria::forTenants(['a'])->page(-1)];
        yield 'zero limit' => [static fn (): TaskCriteria => TaskCriteria::forTenants(['a'])->page(0, 0)];
        yield 'big limit' => [static fn (): TaskCriteria => TaskCriteria::forTenants(['a'])->page(0, 501)];
        yield 'inverted window' => [static fn (): TaskCriteria => TaskCriteria::forTenants(['a'])->dueBetween(new DateTimeImmutable('2026-02-01'), new DateTimeImmutable('2026-01-01'))];
        yield 'too many boards' => [static fn (): TaskCriteria => TaskCriteria::forBoards(array_map(strval(...), range(1, 501)))];
        yield 'board negative offset' => [static fn (): BoardCriteria => BoardCriteria::forTenants(['a'])->page(-1)];
        yield 'board big limit' => [static fn (): BoardCriteria => BoardCriteria::forTenants(['a'])->page(0, 501)];
        yield 'too many tenants' => [static fn (): BoardCriteria => BoardCriteria::forTenants(array_map(static fn (int $i): string => 't' . $i, range(1, 501)))];
    }

    #[DataProvider('badCriteria')]
    public function testCriteriaRefuseOutOfRangeValues(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build();
    }

    public function testBoardCriteria(): void
    {
        $criteria = BoardCriteria::forTenants(['acme', ' x'])->withArchived(null)->page(5, 10);
        self::assertSame([['acme'], null, 5, 10], [$criteria->tenantKeys, $criteria->archived, $criteria->offset, $criteria->limit]);
    }

    public function testHookEventIdGetters(): void
    {
        $user = new class {
            public function getId(): string
            {
                return 'u-9';
            }
        };
        $list = new BoardListQueryEvent($user, 'acme');
        self::assertSame('u-9', $list->getSubjectId());
        self::assertInstanceOf(DateTimeImmutable::class, $list->getOccurredAt());
        self::assertNull((new BoardListQueryEvent(new stdClass()))->getSubjectId());

        $check = new ColumnDeleteCheckEvent('b', null, ['c'], $user);
        self::assertSame('u-9', $check->getUserId());
        self::assertSame($check->occurredAt, $check->getOccurredAt());

        $created = new ColumnCreatedEvent('b', 'acme', 'c', 'Name', 0, 'u-9');
        self::assertSame(['b', 'u-9'], [$created->getBoardId(), $created->getActorId()]);
        self::assertSame($created->occurredAt, $created->getOccurredAt());
    }

    public function testTaskChangedCarriesTheCurrentRevision(): void
    {
        self::assertSame(4, (new TaskChangedException(4))->currentRevision);
    }
}
