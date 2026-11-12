<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Port;

use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\BoardAwareTaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Import\BoardScopedTaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Port\Ports;
use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyPolicyInterface;
use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyResolverInterface;
use Nowo\TaskBoardBundle\Tests\Support\Legacy\Legacy2141PortDecisions;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 2.15.0: the decisions 2.15 routes through {@see Ports} equal the LITERAL v2.14.1 code
 * ({@see Legacy2141PortDecisions}) for every service 2.14 could have (the deprecated interfaces, alone or with the twin),
 * and a twin-only service gives the answer of the equivalent 2.14 implementation.
 */
final class PortEquivalenceTest extends TestCase
{
    use PortFixtures;

    public function testImportUserMatchesTheLegacyCodeForEveryLegacyResolver(): void
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
        $aware = new class($ana, $bob) implements BoardAwareTaskImportUserResolverInterface {
            public function __construct(private readonly object $plain, private readonly object $onBoard)
            {
            }

            public function resolveByEmail(string $email): object
            {
                return $this->plain;
            }

            public function resolveByEmailForBoard(string $email, TaskBoard $board): ?object
            {
                return $board->getTenantKey() === 'acme' && $email === 'bob@example.com' ? $this->onBoard : null;
            }
        };
        $both = new class($ana, $bob) implements BoardAwareTaskImportUserResolverInterface, BoardScopedTaskImportUserResolverInterface {
            public function __construct(private readonly object $plain, private readonly object $onBoard)
            {
            }

            public function resolveByEmail(string $email): object
            {
                return $this->plain;
            }

            public function resolveByEmailForBoard(string $email, TaskBoard $board): object
            {
                return $this->onBoard;
            }

            public function resolveByEmailOnBoard(string $email, TaskBoardSnapshot $board): ?object
            {
                return null; // never asked: the deprecated interface wins until 3.0
            }
        };

        foreach ([$plain, $aware, $both] as $resolver) {
            foreach (['ana@example.com', 'bob@example.com', 'nobody@example.com'] as $email) {
                self::assertSame(Legacy2141PortDecisions::importUser($resolver, $email, $board), Ports::importUser($resolver, $email, $board));
            }
        }
    }

    public function testATwinOnlyImportResolverAnswersAsItsLegacyEquivalent(): void
    {
        $board = $this->board();
        $bob   = new stdClass();
        $twin  = new class($bob) implements BoardScopedTaskImportUserResolverInterface {
            public function __construct(private readonly object $onBoard)
            {
            }

            public function resolveByEmail(string $email): ?object
            {
                return null;
            }

            public function resolveByEmailOnBoard(string $email, TaskBoardSnapshot $board): ?object
            {
                return $board->tenantKey === 'acme' && $email === 'bob@example.com' ? $this->onBoard : null;
            }
        };
        $legacy = new class($bob) implements BoardAwareTaskImportUserResolverInterface {
            public function __construct(private readonly object $onBoard)
            {
            }

            public function resolveByEmail(string $email): ?object
            {
                return null;
            }

            public function resolveByEmailForBoard(string $email, TaskBoard $board): ?object
            {
                return $board->getTenantKey() === 'acme' && $email === 'bob@example.com' ? $this->onBoard : null;
            }
        };

        foreach (['bob@example.com', 'ana@example.com'] as $email) {
            self::assertSame(Legacy2141PortDecisions::importUser($legacy, $email, $board), Ports::importUser($twin, $email, $board));
        }
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
                    $where->reachesBoard($board),
                );
                self::assertSame($where->reachesBoard($board), $where->reachesBoardOf($board->getId(), $board->getTenantKey()));
            }
        }
    }
}
