<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Port;

use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\BoardScopedTaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Port\Internal\AttributionOnlyResolverInterface;
use Nowo\TaskBoardBundle\Port\Ports;
use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyPolicyInterface;
use Nowo\TaskBoardBundle\Tests\Support\Legacy\Legacy2141PortDecisions;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 2.15.0: the decisions 2.15 routes through {@see Ports} equal the LITERAL v2.14.1 code
 * ({@see Legacy2141PortDecisions}), and a framework-free service gives the answer of the equivalent 2.14 implementation
 * (since 3.0.0 the bundle's internal seams stand for the removed 2.14 interfaces).
 */
final class PortEquivalenceTest extends TestCase
{
    use PortFixtures;

    /** Since 3.0.0 (the board-aware 2.14 interface is removed): a board-scoped resolver is told the board, a plain one not. */
    public function testImportUserAsksTheBoardScopedResolverOrThePlainOne(): void
    {
        $board = $this->board();
        $ana   = new stdClass();
        $bob   = new stdClass();

        $plain = new class($ana) implements TaskImportUserResolverInterface {
            public function __construct(private readonly object $user)
            {
            }

            public function resolveByEmail(string $email): ?object
            {
                return $email === 'ana@example.com' ? $this->user : null;
            }
        };
        $scoped = new class($ana, $bob) implements BoardScopedTaskImportUserResolverInterface {
            public function __construct(private readonly object $plain, private readonly object $onBoard)
            {
            }

            public function resolveByEmail(string $email): object
            {
                return $this->plain;
            }

            public function resolveByEmailOnBoard(string $email, TaskBoardSnapshot $board): ?object
            {
                return $board->tenantKey === 'acme' && $email === 'bob@example.com' ? $this->onBoard : null;
            }
        };

        self::assertSame($ana, Ports::importUser($plain, 'ana@example.com', $board));
        self::assertNull(Ports::importUser($plain, 'bob@example.com', $board));
        self::assertSame($bob, Ports::importUser($scoped, 'bob@example.com', $board));
        self::assertNull(Ports::importUser($scoped, 'ana@example.com', $board), 'the board-scoped answer, never the plain one');
    }

    public function testAttributionMatchesTheLegacyCodeAndTheTwinAnswersAsItsEquivalent(): void
    {
        $board = $this->board();
        $user  = new stdClass();

        $legacy = new class implements AttributionOnlyResolverInterface {
            public function isAttributionOnly(TaskBoard $board, object $user): bool
            {
                return $board->getTenantKey() === 'acme';
            }
        };
        $twin = new class implements AttributionOnlyPolicyInterface {
            public function isAttributionOnly(TaskBoardSnapshot $board, object $user): bool
            {
                return $board->tenantKey === 'acme';
            }
        };
        $both = new class implements AttributionOnlyResolverInterface, AttributionOnlyPolicyInterface {
            public function isAttributionOnly(TaskBoard|TaskBoardSnapshot $board, object $user): bool
            {
                return $board instanceof TaskBoard; // the deprecated interface is asked: true
            }
        };

        foreach ([$legacy, $both, new stdClass()] as $resolver) {
            self::assertSame(Legacy2141PortDecisions::isAttributionOnly($resolver, $board, $user), Ports::isAttributionOnly($resolver, $board, $user));
        }
        self::assertSame(Legacy2141PortDecisions::isAttributionOnly($legacy, $board, $user), Ports::isAttributionOnly($twin, $board, $user));
    }

    public function testReachesBoardMatchesTheLegacyCode(): void
    {
        $inAcme   = $this->board();
        $noTenant = new TaskBoard('Loose', 'loose', null);
        $wheres   = [
            [TaskApiWhere::everything(), true, []],
            [TaskApiWhere::everything(['acme']), true, []],
            [TaskApiWhere::limitedTo(['acme'], []), false, []],
            [TaskApiWhere::limitedTo([], [$noTenant->getId()]), false, [$noTenant->getId()]],
            [TaskApiWhere::limitedTo([], []), false, []],
        ];

        foreach ($wheres as [$where, $everything, $boardIds]) {
            foreach ([$inAcme, $noTenant] as $board) {
                self::assertSame(
                    Legacy2141PortDecisions::reachesBoard($everything, $boardIds, $where->reachesTenant(...), $board),
                    $where->reachesBoardOf($board->getId(), $board->getTenantKey()),
                );
            }
        }
    }
}
