<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Search;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Search\Header\SearchHits;
use Nowo\TaskBoardBundle\Search\Header\TaskHeaderSearch;
use Nowo\TaskBoardBundle\Search\Header\TaskSearchExtras;
use Nowo\TaskBoardBundle\Search\Header\TaskTextMatch;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;

use function sprintf;

use const STDERR;

/**
 * Since 2.26.0: the header search finds a task by number, board key or external id (number matches first), matches
 * descriptions only with words long enough, and gives each row its column, done flag and assignee initials — on a real
 * database.
 */
final class HeaderSearchReferencesTest extends HeaderSearchTestCase
{
    protected function setUp(): void
    {
        static::$taskBoardConfig = ['task_numbers' => ['enabled' => true]];
        parent::setUp();
    }

    public function testFindsATaskByNumberOrBoardKeyAndRanksItFirst(): void
    {
        $board = $this->board('Dev board');
        $board->setNumberPrefix('DEV');
        $this->em->flush();
        $this->described($board, 'First', null);
        $second = $this->described($board, 'Second', null);
        $number = $second->getNumber();
        self::assertNotNull($number);
        // A task whose title holds the same digits: found as text, after the number match.
        $this->described($this->board('Other board'), 'Release ' . $number, null);

        foreach (['#' . $number, (string) $number, 'DEV-' . $number, 'dev-' . $number] as $query) {
            $found = $this->search()->search($this->viewer($this->owner), $query, 10);
            self::assertNotEmpty($found->hits, $query);
            self::assertSame('Second', $found->hits[0]->title, $query . ': the number match comes first');
            self::assertTrue($found->hits[0]->byNumber, $query);
            self::assertSame('DEV-' . $number . ' · Dev board', $found->hits[0]->subtitle);
        }
        self::assertCount(2, $this->search()->search($this->viewer($this->owner), (string) $number, 10)->hits, 'number and text matches');
        self::assertCount(1, $this->search()->search($this->viewer($this->owner), 'DEV-' . $number, 10)->hits);
        self::assertSame([], $this->search()->search($this->viewer($this->owner), 'OPS-' . $number, 10)->hits, 'another prefix');
        self::assertSame([], $this->search()->search($this->viewer($this->owner), '#99999', 10)->hits);
    }

    public function testANumberNeverOpensATaskTheViewerMayNotSee(): void
    {
        $bob    = $this->user('bob@example.com');
        $hidden = $this->described($this->board('Bob board', creator: $bob), 'Secret', null);
        $denied = $this->described($this->board('Mine'), 'Denied', null);
        $this->listen(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($denied): void {
            if ($event->getTaskId() === $denied->getId()) {
                $event->deny();
            }
        });

        self::assertEquals(new SearchHits(), $this->search()->search($this->viewer($this->owner), '#' . $hidden->getNumber(), 10));
        self::assertEquals(new SearchHits(), $this->search()->search($this->viewer($this->owner), '#' . $denied->getNumber(), 10));
    }

    public function testFindsATaskByItsExternalIdExactly(): void
    {
        $board    = $this->board();
        $imported = $this->described($board, 'Imported task', null);
        $imported->addLink(new TaskLink($imported, TaskLinkType::Url, 'https://app.clickup.com/t/86b2xyz', null, '86b2xyz', importSource: 'clickup'));
        $plain = $this->described($board, 'Plain link', null);
        $plain->addLink(new TaskLink($plain, TaskLinkType::Url, 'https://example.com/zz9', null, 'zz9_ext'));
        $this->em->flush();

        self::assertSame(['Imported task'], self::titles($this->search()->search($this->viewer($this->owner), '86b2xyz', 10)->hits));
        self::assertSame([], $this->search()->search($this->viewer($this->owner), '86b2', 10)->hits, 'exact, not a prefix');
        self::assertSame([], $this->search()->search($this->viewer($this->owner), 'zz9_ext', 10)->hits, 'only ids an import or a sync stored');
        self::assertFalse($this->search()->search($this->viewer($this->owner), '86b2xyz', 10)->hits[0]->byNumber);
    }

    public function testShortWordsMatchTitlesOnly(): void
    {
        $board = $this->board();
        $this->described($board, 'Plan', 'xy marks the spot');
        $this->described($board, 'xy in the title', null);
        $this->described($board, 'Other', 'the xyz field');

        self::assertSame(['xy in the title'], self::titles($this->search()->search($this->viewer($this->owner), 'xy', 10)->hits), 'two letters: titles only');
        self::assertSame(['Other'], self::titles($this->search()->search($this->viewer($this->owner), 'xyz', 10)->hits), 'three letters: descriptions too');
        self::assertSame(['Other', 'Plan', 'xy in the title'], self::titles($this->search(descriptionMinLength: 2)->search($this->viewer($this->owner), 'xy', 10)->hits), 'two letters allowed: descriptions too');
    }

    public function testABlankTextFindsNothing(): void
    {
        $qb = $this->em->createQueryBuilder()->select('t')->from(Task::class, 't');

        self::assertNull((new TaskTextMatch())->apply($qb, '   '));
        self::assertStringContainsString('1 = 0', $qb->getDQL());
    }

    public function testRowsCarryTheColumnTheDoneFlagAndTheAssigneeInitials(): void
    {
        $board = $this->board('Roadmap', ['To do', 'Doing', 'Done']);
        $ada   = $this->user('ada.lovelace@example.com');
        $grace = $this->user('grace@example.com');
        $open  = $this->task($board, 'Report open', 1);
        $open->addMember(new TaskMember($open, $ada, TaskMemberRole::Assignee));
        $open->addMember(new TaskMember($open, $grace, TaskMemberRole::Assignee));
        $open->addMember(new TaskMember($open, $this->owner, TaskMemberRole::Watcher));
        $done = $this->task($board, 'Report done', 2);
        $done->markCompleted();
        $this->em->flush();

        $hits = [];
        foreach ($this->search()->search($this->viewer($this->owner), 'report', 10)->hits as $hit) {
            $hits[$hit->title] = [$hit->status, $hit->done, $hit->assignees];
        }

        self::assertSame(['Doing', false, ['AL', 'G']], $hits['Report open']);
        self::assertSame(['Done', true, []], $hits['Report done']);
    }

    public function testManyAssigneesAreCountedAndTheQueriesStayFlat(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Crowded');
        foreach (['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com', 'e@example.com'] as $email) {
            $task->addMember(new TaskMember($task, $this->user($email), TaskMemberRole::Assignee));
        }
        $this->em->flush();

        self::assertSame(['A', 'B', 'C', '+2'], $this->search()->search($this->viewer($this->owner), 'crowded', 10)->hits[0]->assignees);
        self::assertSame([$task->getId() => ['A', 'B', 'C', '+2']], (new TaskSearchExtras($this->em))->assigneeInitials([$task]), 'without the user references: a join');
        self::assertSame([], (new TaskSearchExtras($this->em))->assigneeInitials([]));

        $small = $this->queriesFor(3);
        $large = $this->queriesFor(12);
        self::assertSame($small, $large, 'no query per row');
        self::assertLessThanOrEqual(4, $large, 'teams, boards, tasks, assignees');
        fwrite(STDERR, sprintf("\n[budget] header search with extras: %d queries\n", $large));
    }

    private function queriesFor(int $rows): int
    {
        self::ensureKernelShutdown();
        $this->setUp();
        $board = $this->board();
        for ($i = 0; $i < $rows; ++$i) {
            $task = $this->task($board, 'Budget ' . $i);
            $task->addMember(new TaskMember($task, $this->user('u' . $i . '@example.com'), TaskMemberRole::Assignee));
        }
        $this->em->flush();
        $viewer = $this->viewer($this->owner);

        return $this->queries(fn (): SearchHits => $this->search()->search($viewer, 'budget', 20));
    }

    private function search(int $descriptionMinLength = 3): TaskHeaderSearch
    {
        $container = self::getContainer();
        $checker   = $container->get(TaskBoardResourceAccessChecker::class);
        $users     = $container->get(TaskBoardUsers::class);
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $checker);
        self::assertInstanceOf(TaskBoardUsers::class, $users);

        return new TaskHeaderSearch($this->scope(), $this->em, $checker, $this->mapper(), 100, new TaskTextMatch($descriptionMinLength), new TaskSearchExtras($this->em, $users));
    }
}
