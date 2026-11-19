<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Search;

use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\SearchScopeEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Search\Header\SearchHit;
use Nowo\TaskBoardBundle\Search\Header\SearchHits;
use Nowo\TaskBoardBundle\Tests\Functional\App\MemberTenantResolver;

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
        });

        // Limit 1: the excluded board never takes the first row.
        $found = $this->tasks()->search($this->viewer($this->owner, 'scope-1'), 'spec', 1);
        self::assertCount(1, $found->hits);
        self::assertSame(2, $found->total);
        self::assertSame(['First board', 'Third board'], self::titles($this->boards()->search($this->viewer($this->owner), 'board', 10)->hits));
        self::assertSame([[SearchScopeEvent::GROUP_TASKS, 'scope-1', null, (string) $this->owner->getId()], [SearchScopeEvent::GROUP_BOARDS, null, null, (string) $this->owner->getId()]], $seen);
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
        self::assertLessThanOrEqual(3, $large['tasks']);
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
