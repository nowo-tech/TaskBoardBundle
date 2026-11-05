<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync\People;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Import\BoardAwareTaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Event\SyncPeopleReprojectedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonReassignedEvent;
use Nowo\TaskBoardBundle\Sync\Hook\DefaultSyncPersonResolver;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonMatch;
use Nowo\TaskBoardBundle\Sync\People\PeopleScanResult;
use Nowo\TaskBoardBundle\Sync\People\ReassignReport;
use Nowo\TaskBoardBundle\Sync\People\ScannedPerson;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\View\SyncPersonView;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

/**
 * Since 1.25: the people value objects, the default resolver (1.23 semantics) and the Twig functions without sync.
 */
final class PeopleUnitTest extends TestCase
{
    public function testSyncUserIdsComparisonAndEmailNormalisation(): void
    {
        $a = new PeopleUnitUser(7, 'a@example.test');
        self::assertSame('7', SyncUser::id($a));
        self::assertNull(SyncUser::id(null));
        self::assertNull(SyncUser::id(new stdClass()));
        self::assertNull(SyncUser::id(new PeopleUnitUser(['no'], 'x@example.test')));
        self::assertSame('s', SyncUser::id(new PeopleUnitUser(new class implements Stringable {
            public function __toString(): string
            {
                return 's';
            }
        }, 'x@example.test')));
        self::assertTrue(SyncUser::same($a, $a));
        self::assertTrue(SyncUser::same($a, new PeopleUnitUser(7, 'b@example.test')));
        self::assertFalse(SyncUser::same($a, new PeopleUnitUser(8, 'a@example.test')));
        self::assertFalse(SyncUser::same($a, null));
        self::assertFalse(SyncUser::same(null, null));
        self::assertFalse(SyncUser::same(new stdClass(), new stdClass()));
        self::assertNull(SyncUser::normalizeEmail('  '));
        self::assertNull(SyncUser::normalizeEmail(null));
        self::assertSame('josé@example.test', SyncUser::normalizeEmail(" JOSE\u{0301}@Example.TEST "));
    }

    public function testMappingStatesAndValueObjects(): void
    {
        self::assertTrue(TaskSyncPersonMapping::Auto->isMapped());
        self::assertTrue(TaskSyncPersonMapping::Manual->isMapped());
        self::assertFalse(TaskSyncPersonMapping::Suggested->isMapped());
        self::assertTrue(TaskSyncPersonMapping::External->isHumanDecision());
        self::assertFalse(TaskSyncPersonMapping::Auto->isHumanDecision());

        $person = new ScannedPerson('9001', 'User 1', null, 'guest', ['member' => 1]);
        self::assertSame(ScannedPerson::MATCH_NONE, $person->match);
        self::assertNull($person->boardAccess);
        $result = new PeopleScanResult([$person], null, 3);
        self::assertTrue($result->partial);
        self::assertNull($result->directoryId);
        $view = new SyncPersonView('p', 'User 1', 'clickup', true, false);
        self::assertNull($view->boardAccess);

        $report = new ReassignReport(tasks: 1);
        $moved  = new SyncPersonReassignedEvent('d', 'p', null, '7', '1', 'r', $report);
        self::assertSame($report, $moved->report);
        self::assertSame('r', $moved->reassignId);
        $reprojected = new SyncPeopleReprojectedEvent('7', ['b'], $report, 'membership_added');
        self::assertSame(['b'], $reprojected->boardIds);
        self::assertSame('membership_added', $reprojected->trigger);
    }

    public function testTheDefaultResolverKeeps123Semantics(): void
    {
        $board    = $this->board();
        $verified = new PeopleUnitVerifiedUser(1, 'one@example.test', true);
        $plain    = new PeopleUnitUser(2, 'two@example.test');
        $users    = new class([$verified, $plain]) implements TaskImportUserResolverInterface {
            /** @param list<object> $users */
            public function __construct(private readonly array $users)
            {
            }

            public function resolveByEmail(string $email): ?object
            {
                foreach ($this->users as $user) {
                    if (method_exists($user, 'getEmail') && $user->getEmail() === $email) {
                        return $user;
                    }
                }

                return null;
            }
        };
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $class, mixed $id): ?object => $id === '2' ? $plain : null);
        $ref = new RemotePersonRef('9001', 'User 1', 'one@example.test');

        $strict = new DefaultSyncPersonResolver($users, $em, PeopleUnitUser::class);
        self::assertSame('board:' . $board->getId(), $strict->directoryScope($board));
        self::assertSame([], $strict->candidates($board));
        self::assertTrue($strict->isCandidate($board, $plain), 'no assignee provider: everyone (1.23)');
        self::assertEquals(new SyncPersonMatch($verified, true), $strict->autoMatch($board, $ref, 'one@example.test'));
        self::assertEquals(new SyncPersonMatch($plain, false), $strict->autoMatch($board, $ref, 'two@example.test'), 'no isVerified(): only suggested by default');
        self::assertNull($strict->autoMatch($board, $ref, 'nobody@example.test'));
        self::assertSame($plain, $strict->candidateById($board, '2'));
        self::assertNull($strict->candidateById($board, '3'));

        $lenient = new DefaultSyncPersonResolver($users, $em, PeopleUnitUser::class, null, false);
        self::assertEquals(new SyncPersonMatch($plain, true), $lenient->autoMatch($board, $ref, 'two@example.test'), 'require_verified_email: false = 1.23 parity');
        self::assertEquals(new SyncPersonMatch(new PeopleUnitVerifiedUser(3, 'x', false), false), new SyncPersonMatch(new PeopleUnitVerifiedUser(3, 'x', false), false));

        // With an assignee provider: candidates filtered by the query and the limit, and scoped by canBeAssigned().
        $assignees = new class([$verified, $plain]) implements TaskAssigneeProviderInterface {
            /** @param list<object> $users */
            public function __construct(private readonly array $users)
            {
            }

            public function assignableUsers(TaskBoard $board): array
            {
                return $this->users;
            }

            public function canBeAssigned(TaskBoard $board, object $user): bool
            {
                return SyncUser::id($user) === '1';
            }
        };
        $boardAware = new class($users) implements BoardAwareTaskImportUserResolverInterface {
            public int $calls = 0;

            public function __construct(private readonly TaskImportUserResolverInterface $inner)
            {
            }

            public function resolveByEmail(string $email): ?object
            {
                return $this->inner->resolveByEmail($email);
            }

            public function resolveByEmailForBoard(string $email, TaskBoard $board): ?object
            {
                ++$this->calls;

                return $this->inner->resolveByEmail($email);
            }
        };
        $scoped = new DefaultSyncPersonResolver($boardAware, $em, PeopleUnitUser::class, $assignees);
        self::assertSame([$verified, $plain], $scoped->candidates($board));
        self::assertSame([$verified], $scoped->candidates($board, 'ONE@'));
        self::assertSame([$verified], $scoped->candidates($board, '', 1));
        self::assertFalse($scoped->isCandidate($board, $plain));
        self::assertNull($scoped->autoMatch($board, $ref, 'two@example.test'), 'never outside the candidates');
        self::assertNotNull($scoped->autoMatch($board, $ref, 'one@example.test'));
        self::assertSame(2, $boardAware->calls);
        self::assertNull($scoped->candidateById($board, '2'));
    }

    public function testTheTwigFunctionsWithoutSync(): void
    {
        $extension = new TaskBoardTwigExtension('base.html.twig', 'tabler');
        $board     = $this->board();
        $task      = new Task(board: $board, title: 'T', creator: new PeopleUnitUser(1, 'a@example.test'));

        self::assertNull($extension->syncCreator($task));
        self::assertNull($extension->syncCommentAuthor(new TaskComment($task, new PeopleUnitUser(1, 'a@example.test'), 'x')));
        self::assertSame([], $extension->syncUnmatched($task));
        self::assertSame('person:1', $extension->syncToken('person:1'));
        self::assertSame('', $extension->syncToken(null));
    }

    private function board(): TaskBoard
    {
        return new TaskBoard('Roadmap', 'roadmap', new PeopleUnitUser(1, 'a@example.test'));
    }
}

/** A host user. */
class PeopleUnitUser
{
    public function __construct(
        private readonly mixed $id,
        private readonly string $email,
    ) {
    }

    public function getId(): mixed
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }
}

/** A host user with a verified e-mail flag. */
final class PeopleUnitVerifiedUser extends PeopleUnitUser
{
    public function __construct(mixed $id, string $email, private readonly bool $verified)
    {
        parent::__construct($id, $email);
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }
}
