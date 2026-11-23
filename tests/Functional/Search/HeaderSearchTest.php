<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Search;

use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\BoardListQueryEvent;
use Nowo\TaskBoardBundle\Event\SearchScopeEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Search\Header\SearchableBoard;
use Nowo\TaskBoardBundle\Search\Header\SearchBoardScope;
use Nowo\TaskBoardBundle\Search\Header\SearchHit;
use Nowo\TaskBoardBundle\Search\Header\SearchHits;
use Nowo\TaskBoardBundle\Search\Header\SearchViewer;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Security\TaskBoardTeamMembershipResolverInterface;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Nowo\TaskBoardBundle\Tests\Functional\App\MemberTenantResolver;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function array_keys;
use function array_map;
use function range;
use function sprintf;
use function str_repeat;

use const STDERR;

/**
 * Since 2.25.0 (header search): which tasks and boards the header search finds for a viewer — visibility, tenants,
 * archived rows, host denials, the scope event, LIKE escaping and the query budget — on a real database.
 */
final class HeaderSearchTest extends HeaderSearchTestCase
{
    public function testFindsTasksOnlyOnBoardsTheViewerMayOpen(): void
    {
        $alice = $this->user('alice@example.com');
        $bob   = $this->user('bob@example.com');
        $this->described($this->board('Owner board'), 'Release notes', null);
        $this->described($this->teamBoard('Team board', $bob, [$alice]), 'Release plan', 'Ship the release');
        $this->described($this->board('Bob board', creator: $bob), 'Release secret', null);

        $found = $this->tasks()->search($this->viewer($alice), 'release', 10);

        self::assertSame(['Release plan'], self::titles($found->hits));
        self::assertSame(1, $found->total);
        self::assertFalse($found->partial);
        $hit = $found->hits[0];
        self::assertSame(SearchHit::KIND_TASK, $hit->kind);
        self::assertStringStartsWith('/tools/task-board/task/', $hit->url);
        self::assertSame('Team board', $hit->subtitle);
        self::assertSame('Ship the release', $hit->excerpt);
        self::assertNotNull($hit->boardId);

        self::assertSame(['Release notes'], self::titles($this->tasks()->search($this->viewer($this->owner), 'release notes', 10)->hits));
        self::assertSame(['Release secret'], self::titles($this->tasks()->search($this->viewer($bob), 'secret', 10)->hits));
    }

    public function testMatchesTheDescriptionAndEveryWord(): void
    {
        $board = $this->board();
        $this->described($board, 'Invoice export', 'Runs every night for the accounting team');
        $this->described($board, 'Invoice import', null);

        self::assertSame(['Invoice export'], self::titles($this->tasks()->search($this->viewer($this->owner), 'invoice ACCOUNTING', 10)->hits));
        self::assertSame(['Invoice export', 'Invoice import'], self::titles($this->tasks()->search($this->viewer($this->owner), '  invoice  ', 10)->hits));
        self::assertEquals(new SearchHits(), $this->tasks()->search($this->viewer($this->owner), '   ', 10));
    }

    public function testLeavesArchivedTasksAndBoardsOut(): void
    {
        $board = $this->board();
        $this->described($board, 'Kept roadmap', null);
        $this->described($board, 'Old roadmap', null)->archive();
        $archived = $this->board('Archived board');
        $this->described($archived, 'Archived roadmap', null);
        $archived->archive();
        $this->em->flush();

        self::assertSame(['Kept roadmap'], self::titles($this->tasks()->search($this->viewer($this->owner), 'roadmap', 10)->hits));
        self::assertSame(['Roadmap'], self::titles($this->boards()->search($this->viewer($this->owner), 'roadmap', 10)->hits));
        self::assertSame([], $this->boards()->search($this->viewer($this->owner), 'archived', 10)->hits);
    }

    public function testATaskAccessDenyHidesTheTaskAndItsCount(): void
    {
        $board  = $this->board();
        $hidden = $this->described($board, 'Budget hidden', null);
        $this->described($board, 'Budget shown', null);
        $this->listen(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($hidden): void {
            if ($event->getTaskId() === $hidden->getId()) {
                $event->deny();
            }
        });

        $found = $this->tasks()->search($this->viewer($this->owner), 'budget', 10);

        self::assertSame(['Budget shown'], self::titles($found->hits));
        self::assertSame(1, $found->total);
        // Oracle-free: the denied task's own title finds what an unknown title finds.
        self::assertEquals($this->tasks()->search($this->viewer($this->owner), 'nothing-like-this', 10), $this->tasks()->search($this->viewer($this->owner), 'budget hidden', 10));
    }

    public function testABoardAccessDenyHidesTheBoardAndItsTasks(): void
    {
        $denied = $this->board('Denied board');
        $this->described($denied, 'Denied task', null);
        $this->described($this->board('Open board'), 'Open task', null);
        $this->listen(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use ($denied): void {
            if ($event->getBoardId() === $denied->getId()) {
                $event->deny();
            }
        });

        self::assertSame(['Open task'], self::titles($this->tasks()->search($this->viewer($this->owner), 'task', 10)->hits));
        $boards = $this->boards()->search($this->viewer($this->owner), 'board', 10);
        self::assertSame(['Open board'], self::titles($boards->hits));
        self::assertSame(1, $boards->total);
    }

    public function testABoardAccessGrantOpensABoardOfSomeoneElse(): void
    {
        $bob   = $this->user('bob@example.com');
        $board = $this->board('Shared by host', creator: $bob);
        $this->described($board, 'Granted task', null);
        $this->listen(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use ($board): void {
            if ($event->getBoardId() === $board->getId()) {
                $event->grant();
            }
        });

        self::assertSame(['Granted task'], self::titles($this->tasks()->search($this->viewer($this->owner), 'granted', 10)->hits));
    }

    public function testTheScopeEventNarrowsInSqlBeforeTheCut(): void
    {
        $first  = $this->board('First board');
        $second = $this->board('Second board');
        $third  = $this->board('Third board');
        foreach ([$first, $second, $third] as $board) {
            $this->described($board, 'Spec on ' . $board->getName(), null);
        }
        $seen = [];
        $this->listen(TaskBoardEvents::SEARCH_SCOPE, static function (SearchScopeEvent $event) use (&$seen, $first, $second, $third): void {
            $seen[] = [$event->getGroup(), $event->getScope(), $event->getRequestTenantKey(), $event->getSubjectId()];
            $event->onlyBoards([$first->getId(), $second->getId(), $third->getId()]);
            $event->excludeBoards([$second->getId()]);
            if ($event->getScope() === 'scope-1') {
                $event->acceptScope();
            }
        });

        // Limit 1: the excluded board never takes the first row.
        $found = $this->tasks()->search($this->viewer($this->owner, 'scope-1'), 'spec', 1);
        self::assertCount(1, $found->hits);
        self::assertSame(2, $found->total);
        self::assertSame(['First board', 'Third board'], self::titles($this->boards()->search($this->viewer($this->owner), 'board', 10)->hits));
        self::assertSame([[SearchScopeEvent::GROUP_TASKS, 'scope-1', null, (string) $this->owner->getId()], [SearchScopeEvent::GROUP_BOARDS, null, null, (string) $this->owner->getId()]], $seen);
    }

    public function testTheBundlesOwnScopesAndUnacceptedHostScopes(): void
    {
        $first  = $this->board('First board');
        $second = $this->board('Second board');
        $this->described($first, 'Scoped task', null);
        $this->described($second, 'Scoped task', null);

        $found = $this->tasks()->search($this->viewer($this->owner, 'board:' . $first->getId()), 'scoped', 10);
        self::assertSame(1, $found->total);
        self::assertSame($first->getId(), $found->hits[0]->boardId);
        self::assertSame([], $this->tasks()->search($this->viewer($this->owner, 'project:7'), 'scoped', 10)->hits, 'a host scope nobody accepts');
        self::assertSame([], $this->tasks()->search($this->viewer($this->owner, 'board:'), 'scoped', 10)->hits);
        self::assertSame([], $this->boards()->search($this->viewer($this->owner, 'tenant:acme'), 'board', 10)->hits, 'no board has that tenant');
    }

    /**
     * Audit VL-1: boards the viewer cannot open, newer than the one they can, never push it out of the scope — the
     * default rule filters in SQL when no listener may grant.
     */
    public function testHiddenNewerBoardsNeverPushOutAVisibleOne(): void
    {
        $bob = $this->user('bob@example.com');
        $this->described($this->board('Mine'), 'Needle task', null);
        for ($i = 0; $i < 4; ++$i) {
            $this->described($this->board('Bob ' . $i, creator: $bob), 'Needle task', null);
        }

        $found = $this->tasks(maxBoards: 1, maxScannedBoards: 1)->search($this->viewer($this->owner), 'needle', 10);

        self::assertSame(1, $found->total);
        self::assertFalse($found->partial);
    }

    /**
     * Audit VL-1: with a BOARD_ACCESS_CHECK listener (which may grant) the default-visible boards come first, a granted
     * board of someone else is still found, and a scan that stops at its bound says so even when it kept nothing.
     */
    public function testWithAListenerTheDefaultBoardsComeFirstAndABoundScanIsPartial(): void
    {
        $bob = $this->user('bob@example.com');
        $this->described($this->board('Mine'), 'Needle task', null);
        $granted = $this->board('Granted', creator: $bob);
        $this->described($granted, 'Needle task', null);
        for ($i = 0; $i < 4; ++$i) {
            $this->described($this->board('Bob ' . $i, creator: $bob), 'Needle task', null);
        }
        $owner = $this->owner;
        $this->listen(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use ($granted, $owner): void {
            if ($event->getBoardId() === $granted->getId() && $event->getSubjectId() === (string) $owner->getId()) {
                $event->grant();
            }
        });

        $first = $this->tasks(maxBoards: 1, maxScannedBoards: 1)->search($this->viewer($this->owner), 'needle', 10);
        self::assertSame(1, $first->total, 'the creator\'s board is read first');
        self::assertSame('Mine', $first->hits[0]->subtitle);
        self::assertTrue($first->partial);

        $all = $this->tasks()->search($this->viewer($this->owner), 'needle', 10);
        self::assertSame(2, $all->total, 'the granted board is searched too');
        self::assertFalse($all->partial);

        $alice = $this->user('alice@example.com');
        $none  = $this->tasks(maxBoards: 1, maxScannedBoards: 2)->search($this->viewer($alice), 'needle', 10);
        self::assertEquals(new SearchHits([], 0, true), $none, 'nothing visible within the bound: empty, but partial');
        self::assertEquals(new SearchHits([], 0, false), $this->tasks()->search($this->viewer($alice), 'needle', 10));
    }

    /** Audit Q3: a BOARD_LIST_QUERY override (the host's index) bounds the search. */
    public function testTheBoardListOverrideBoundsTheSearch(): void
    {
        $listed = $this->board('Listed');
        $this->described($listed, 'Index task', null);
        $this->described($this->board('Unlisted'), 'Index task', null);
        $this->listen(TaskBoardEvents::BOARD_LIST_QUERY, static function (BoardListQueryEvent $event) use ($listed): void {
            $event->overrideList([$listed]);
        });

        $found = $this->tasks()->search($this->viewer($this->owner), 'index', 10);
        self::assertSame(1, $found->total);
        self::assertSame($listed->getId(), $found->hits[0]->boardId);
        self::assertSame(['Listed'], self::titles($this->boards()->search($this->viewer($this->owner), 'listed', 10)->hits));
        self::assertSame([], $this->boards()->search($this->viewer($this->owner), 'unlisted', 10)->hits);
    }

    public function testAnEmptyBoardListOverrideFindsNothing(): void
    {
        $this->described($this->board(), 'Index task', null);
        $this->listen(TaskBoardEvents::BOARD_LIST_QUERY, static function (BoardListQueryEvent $event): void {
            $event->overrideList([]);
        });

        self::assertEquals(new SearchHits(), $this->tasks()->search($this->viewer($this->owner), 'index', 10));
    }

    /** Audit Q3 with tenancy: each tenant's override applies to that tenant only. */
    public function testWithTenancyEachTenantsListOverrideApplies(): void
    {
        $this->boot(self::TENANCY);
        MemberTenantResolver::$members = ['acme' => ['owner@example.com'], 'globex' => ['owner@example.com']];
        $listed                        = $this->board('Acme listed', tenantKey: 'acme');
        $this->described($listed, 'Index task', null);
        $this->described($this->board('Acme unlisted', tenantKey: 'acme'), 'Index task', null);
        $this->described($this->board('Globex board', tenantKey: 'globex'), 'Index task', null);
        $this->listen(TaskBoardEvents::SEARCH_SCOPE, static function (SearchScopeEvent $event): void {
            $event->onlyTenants(['acme', 'globex']);
        });
        $this->listen(TaskBoardEvents::BOARD_LIST_QUERY, static function (BoardListQueryEvent $event) use ($listed): void {
            if ($event->getTenantKey() === 'acme') {
                $event->overrideList([$listed]);
            }
        });

        $found = $this->tasks()->search($this->viewer($this->owner), 'index', 10);
        self::assertSame(2, $found->total);
        self::assertSame(['Acme listed · acme', 'Globex board · globex'], self::subtitles($found->hits));
    }

    /** Audit VL-2: `tenant:<key>` narrows the request's tenant, never widens past it. */
    public function testATenantScopeNeverWidensPastTheRequestTenant(): void
    {
        $this->boot(self::TENANCY);
        MemberTenantResolver::$members = ['acme' => ['owner@example.com'], 'globex' => ['owner@example.com']];
        $this->described($this->board('Acme board', tenantKey: 'acme'), 'Tenant task', null);
        $this->described($this->board('Globex board', tenantKey: 'globex'), 'Tenant task', null);
        $stack = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);

        $stack->push(new Request(attributes: ['tenantSlug' => 'acme']));
        self::assertSame([], $this->tasks()->search($this->viewer($this->owner, 'tenant:globex'), 'tenant', 10)->hits);
        self::assertSame(1, $this->tasks()->search($this->viewer($this->owner, 'tenant:acme'), 'tenant', 10)->total);
        $stack->pop();

        self::assertSame([], $this->tasks()->search($this->viewer($this->owner, 'tenant:globex'), 'tenant', 10)->hits, 'no tenant page: nothing');
    }

    /** The scope without a dispatcher, a tenant cache or the user references: the default rule in SQL, on the user object. */
    public function testAScopeWithoutOptionalCollaborators(): void
    {
        $bob = $this->user('bob@example.com');
        $this->board('Mine');
        $this->board('Bob board', creator: $bob);
        $checker = self::getContainer()->get(TaskBoardResourceAccessChecker::class);
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $checker);
        $scope = new SearchBoardScope($this->em, $checker);

        self::assertSame(['Mine'], array_map(static fn (SearchableBoard $board): string => $board->board->getName(), array_values($scope->boards($this->viewer($this->owner), SearchScopeEvent::GROUP_BOARDS)->boards)));
        self::assertTrue($scope->boards(new SearchViewer(new stdClass()), SearchScopeEvent::GROUP_BOARDS)->isEmpty(), 'no id, no team: the default rule opens nothing');
    }

    /** With tenancy and no tenant cache the tenant context answers directly. */
    public function testWithTenancyAScopeWithoutTheTenantCache(): void
    {
        $this->boot(self::TENANCY);
        MemberTenantResolver::$members = ['acme' => ['owner@example.com']];
        $this->board('Acme board', tenantKey: 'acme');
        $container = self::getContainer();
        $checker   = $container->get(TaskBoardResourceAccessChecker::class);
        $tenants   = $container->get(TaskBoardTenantContext::class);
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $checker);
        self::assertInstanceOf(TaskBoardTenantContext::class, $tenants);
        $this->listen(TaskBoardEvents::SEARCH_SCOPE, static function (SearchScopeEvent $event): void {
            $event->onlyTenants(['acme']);
        });
        $scope = new SearchBoardScope($this->em, $checker, $this->dispatcher(), $tenants);

        $found = array_values($scope->boards($this->viewer($this->owner), SearchScopeEvent::GROUP_BOARDS)->boards);
        self::assertCount(1, $found);
        self::assertSame('acme', $found[0]->tenant?->key);
    }

    /**
     * Audit VL2-1: more than 500 team ids reach SQL in several IN lists (no listener: the default rule filters in SQL), so
     * the board of the 551st team is found; past MAX_TEAMS the search says it is partial.
     */
    public function testMoreThanFiveHundredTeamsAreAllSearched(): void
    {
        $bob  = $this->user('bob@example.com');
        $team = new Team('Team 551');
        $this->em->persist($team);
        $board = $this->board('Far team board', creator: $bob);
        $board->setTeam($team);
        $this->em->flush();
        $this->described($board, 'Far task', null);
        $this->described($this->board('Bob alone', creator: $bob), 'Far task', null);
        $ids      = array_map(static fn (int $i): string => sprintf('00000000-0000-4000-8000-%012d', $i), range(1, 600));
        $ids[550] = $team->getId();

        $found = $this->scopeWithTeams($ids)->boards($this->viewer($this->owner), SearchScopeEvent::GROUP_TASKS);
        self::assertSame([$board->getId()], array_keys($found->boards));
        self::assertFalse($found->capped);

        $many = array_map(static fn (int $i): string => sprintf('00000000-0000-4000-8000-%012d', $i), range(1, SearchBoardScope::MAX_TEAMS + 1));
        $cut  = $this->scopeWithTeams($many)->boards($this->viewer($this->owner), SearchScopeEvent::GROUP_TASKS);
        self::assertSame([], $cut->boards);
        self::assertTrue($cut->capped, 'teams past the cap: partial');
        self::assertTrue($this->warned('TaskBoard header search team list cut'));
    }

    /**
     * A scope whose access checker resolves the viewer's teams to `$teamIds` (a membership resolver), on the kernel's
     * dispatcher (no BOARD_ACCESS_CHECK listener: the default rule filters in SQL).
     *
     * @param list<string> $teamIds
     */
    private function scopeWithTeams(array $teamIds): SearchBoardScope
    {
        $container = self::getContainer();
        $members   = $container->get(TeamMemberRepositoryInterface::class);
        $users     = $container->get(TaskBoardUsers::class);
        self::assertInstanceOf(TeamMemberRepositoryInterface::class, $members);
        self::assertInstanceOf(TaskBoardUsers::class, $users);
        $resolver = new class($teamIds) implements TaskBoardTeamMembershipResolverInterface {
            /** @param list<string> $ids */
            public function __construct(private readonly array $ids)
            {
            }

            public function resolveTeamIds(object $user): array
            {
                return $this->ids;
            }
        };
        $checker = new TaskBoardResourceAccessChecker($members, $resolver, null, $this->dispatcher());

        return new SearchBoardScope($this->em, $checker, $this->dispatcher(), null, $this->logger(), users: $users);
    }

    public function testTheScopeEventDenies(): void
    {
        $this->described($this->board(), 'Anything', null);
        $this->listen(TaskBoardEvents::SEARCH_SCOPE, static function (SearchScopeEvent $event): void {
            $event->deny();
        });

        self::assertEquals(new SearchHits(), $this->tasks()->search($this->viewer($this->owner), 'anything', 10));
        self::assertEquals(new SearchHits(), $this->boards()->search($this->viewer($this->owner), 'roadmap', 10));
    }

    public function testTooManyExclusionsFailClosed(): void
    {
        $this->described($this->board(), 'Anything', null);
        $this->listen(TaskBoardEvents::SEARCH_SCOPE, static function (SearchScopeEvent $event): void {
            $event->excludeBoards(array_map(static fn (int $i): string => 'board-' . $i, range(1, 501)));
        });

        self::assertSame([], $this->tasks()->search($this->viewer($this->owner), 'anything', 10)->hits);
        self::assertTrue($this->warned('TaskBoard header search scope refused: too many exclusions'));
    }

    public function testEscapesLikeWildcards(): void
    {
        $board = $this->board();
        $this->described($board, 'Done 100% of it', null);
        $this->described($board, 'Done 1000 of it', null);
        $this->described($board, 'snake_case name', null);
        $this->described($board, 'snakeXcase name', null);
        $this->described($board, 'Bang! here', null);
        $this->described($board, 'Bang here', null);

        self::assertSame(['Done 100% of it'], self::titles($this->tasks()->search($this->viewer($this->owner), '100%', 10)->hits));
        self::assertSame(['snake_case name'], self::titles($this->tasks()->search($this->viewer($this->owner), 'snake_case', 10)->hits));
        self::assertSame(['Bang! here'], self::titles($this->tasks()->search($this->viewer($this->owner), 'bang!', 10)->hits));
        self::assertSame(['Done 100% of it'], self::titles($this->tasks()->search($this->viewer($this->owner), '%', 10)->hits));
    }

    public function testCutsToTheLimitAndSaysWhenTheScanStopped(): void
    {
        $board = $this->board();
        for ($i = 0; $i < 5; ++$i) {
            $this->described($board, 'Item ' . $i, null);
        }

        $all = $this->tasks()->search($this->viewer($this->owner), 'item', 2);
        self::assertCount(2, $all->hits);
        self::assertSame(5, $all->total);
        self::assertFalse($all->partial);

        $scan = $this->tasks(scanRows: 3)->search($this->viewer($this->owner), 'item', 2);
        self::assertCount(2, $scan->hits);
        self::assertSame(3, $scan->total);
        self::assertTrue($scan->partial);

        self::assertCount(1, $this->tasks()->search($this->viewer($this->owner), 'item', 0)->hits);
        self::assertCount(5, $this->tasks()->search($this->viewer($this->owner), 'item', 500)->hits);
    }

    public function testCapsTheBoardsOfOneSearch(): void
    {
        $this->described($this->board('Board one'), 'Capped task', null);
        $this->described($this->board('Board two'), 'Capped task', null);

        $found = $this->tasks(maxBoards: 1)->search($this->viewer($this->owner), 'capped', 10);

        self::assertCount(1, $found->hits);
        self::assertTrue($found->partial);
        self::assertTrue($this->warned('TaskBoard header search scope capped'));
        self::assertTrue($this->boards(maxBoards: 1)->search($this->viewer($this->owner), 'board', 10)->partial);
    }

    public function testWithTenancySearchesOnlyTheTenantsTheHostNamesAndTheViewerOpens(): void
    {
        $this->boot(self::TENANCY);
        MemberTenantResolver::$members = ['acme' => ['owner@example.com'], 'globex' => ['someone@example.com']];
        $this->described($this->board('Acme board', tenantKey: 'acme'), 'Tenant task', 'In acme');
        $this->described($this->board('Globex board', tenantKey: 'globex'), 'Tenant task', 'In globex');
        $this->described($this->board('No tenant board'), 'Tenant task', 'Nowhere');

        // No tenant page and no host answer: nothing (deny by default).
        self::assertSame([], $this->tasks()->search($this->viewer($this->owner), 'tenant', 10)->hits);

        $this->listen(TaskBoardEvents::SEARCH_SCOPE, static function (SearchScopeEvent $event): void {
            $event->onlyTenants(['acme', 'globex', 'unknown', ' acme']);
        });
        $found = $this->tasks()->search($this->viewer($this->owner), 'tenant', 10);
        self::assertCount(1, $found->hits);
        self::assertSame(1, $found->total);
        self::assertSame('In acme', $found->hits[0]->excerpt);
        self::assertSame('Acme board · acme', $found->hits[0]->subtitle);
        self::assertStringStartsWith('/t/acme/', $found->hits[0]->url);

        $boards = $this->boards()->search($this->viewer($this->owner), 'board', 10);
        self::assertSame(['Acme board'], self::titles($boards->hits));
        self::assertSame('acme', $boards->hits[0]->subtitle);
        self::assertStringStartsWith('/t/acme/', $boards->hits[0]->url);
    }

    public function testWithTenancyATenantPageSearchesItsTenantAndAnUnknownSlugNothing(): void
    {
        $this->boot(self::TENANCY);
        MemberTenantResolver::$members = ['acme' => ['owner@example.com'], 'globex' => ['owner@example.com']];
        $this->described($this->board('Acme board', tenantKey: 'acme'), 'Tenant task', null);
        $this->described($this->board('Globex board', tenantKey: 'globex'), 'Tenant task', null);
        $keys = [];
        $this->listen(TaskBoardEvents::SEARCH_SCOPE, static function (SearchScopeEvent $event) use (&$keys): void {
            $keys[] = $event->getRequestTenantKey();
        });
        $stack = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);

        $stack->push(new Request(attributes: ['tenantSlug' => 'acme']));
        self::assertSame(['Acme board'], self::titles($this->boards()->search($this->viewer($this->owner), 'board', 10)->hits));
        $stack->pop();

        $stack->push(new Request(attributes: ['tenantSlug' => 'initech']));
        self::assertEquals(new SearchHits(), $this->tasks()->search($this->viewer($this->owner), 'tenant', 10));
        $stack->pop();

        self::assertSame(['acme'], $keys, 'an unknown slug never reaches the listeners');
    }

    public function testAnEmptyAllowListFindsNothing(): void
    {
        $this->described($this->board(), 'Anything', null);
        $this->listen(TaskBoardEvents::SEARCH_SCOPE, static function (SearchScopeEvent $event): void {
            $event->onlyBoards([]);
        });

        self::assertSame([], $this->tasks()->search($this->viewer($this->owner), 'anything', 10)->hits);
        self::assertEquals(new SearchHits(), $this->boards()->search($this->viewer($this->owner), ' ', 10));
    }

    public function testWithTenancyExclusionsAndUnusableKeys(): void
    {
        $this->boot(self::TENANCY);
        MemberTenantResolver::$members = ['acme' => ['owner@example.com'], 'initech' => ['owner@example.com']];
        $this->described($this->board('Acme board', tenantKey: 'acme'), 'Tenant task', null);
        $this->described($this->board('Initech board', tenantKey: 'initech'), 'Tenant task', null);
        $this->listen(TaskBoardEvents::SEARCH_SCOPE, static function (SearchScopeEvent $event): void {
            $event->onlyTenants(['acme', 'initech']);
            $event->excludeTenants(['initech']);
        });

        self::assertSame(['Acme board'], self::titles($this->boards()->search($this->viewer($this->owner), 'board', 10)->hits));
    }

    public function testWithTenancyAnOnlyTenantsListOfUnusableKeysFindsNothing(): void
    {
        $this->boot(self::TENANCY);
        MemberTenantResolver::$members = ['acme' => ['owner@example.com']];
        $this->described($this->board('Acme board', tenantKey: 'acme'), 'Tenant task', null);
        $this->listen(TaskBoardEvents::SEARCH_SCOPE, static function (SearchScopeEvent $event): void {
            $event->onlyTenants([' acme', "ac\u{200B}me"]);
        });

        self::assertSame([], $this->boards()->search($this->viewer($this->owner), 'board', 10)->hits);
    }

    public function testALongAllowListIsCutAndLogged(): void
    {
        $board = $this->board();
        $this->described($board, 'Listed task', null);
        $this->listen(TaskBoardEvents::SEARCH_SCOPE, static function (SearchScopeEvent $event) use ($board): void {
            $event->onlyBoards([$board->getId(), ...array_map(static fn (int $i): string => 'board-' . $i, range(1, 600))]);
        });

        self::assertSame(['Listed task'], self::titles($this->tasks()->search($this->viewer($this->owner), 'listed', 10)->hits));
        self::assertTrue($this->warned('TaskBoard header search scope list cut'));
    }

    public function testExcerptsALongDescriptionAroundTheMatch(): void
    {
        $this->described($this->board(), 'Long one', str_repeat('lorem ipsum ', 30) . 'needle in the text ' . str_repeat('dolor sit ', 30));

        $hit = $this->tasks()->search($this->viewer($this->owner), 'needle', 10)->hits[0];

        self::assertNotNull($hit->excerpt);
        self::assertStringContainsString('needle in the text', $hit->excerpt);
        self::assertStringStartsWith('…', $hit->excerpt);
        self::assertStringEndsWith('…', $hit->excerpt);
    }

    /**
     * The query budget: the same queries for 2 boards and 6 matching tasks as for 12 boards and 60, every board on a
     * team (the viewer's teams are read once, not once per board).
     */
    public function testTheQueryBudgetDoesNotGrowWithBoardsOrTasks(): void
    {
        $small = $this->budgetFor(2, 3);
        $large = $this->budgetFor(12, 5);

        self::assertSame($small, $large, 'no query per board or task');
        self::assertLessThanOrEqual(4, $large['tasks'], 'teams, boards, tasks and the assignees of the shown rows');
        self::assertLessThanOrEqual(2, $large['boards']);
        fwrite(STDERR, sprintf("\n[budget] header search: tasks %d, boards %d queries\n", $large['tasks'], $large['boards']));
    }

    /**
     * @return array{tasks: int, boards: int}
     */
    private function budgetFor(int $boards, int $tasksPerBoard): array
    {
        self::ensureKernelShutdown();
        $this->setUp();
        $alice = $this->user('alice@example.com');
        $bob   = $this->user('bob@example.com');
        for ($b = 0; $b < $boards; ++$b) {
            $board = $this->teamBoard('Budget board ' . $b, $bob, [$alice]);
            for ($t = 0; $t < $tasksPerBoard; ++$t) {
                $this->described($board, 'Budget task ' . $b . '-' . $t, 'Budget description');
            }
        }
        $viewer = $this->viewer($alice);

        return [
            'tasks'  => $this->queries(fn (): SearchHits => $this->tasks()->search($viewer, 'budget', 5)),
            'boards' => $this->queries(fn (): SearchHits => $this->boards()->search($viewer, 'budget', 5)),
        ];
    }
}
