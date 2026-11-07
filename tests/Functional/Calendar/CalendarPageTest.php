<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Calendar;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\App\PreferredViewResolver;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Spec 214, block C: placement, modes, URL filters, the cap, read-only and the default view.
 */
final class CalendarPageTest extends CalendarTestCase
{
    public function testAMonthPlacesEachTaskByItsDates(): void
    {
        $board = $this->board();
        $this->dated($board, 'Both', '2026-11-10', '2026-11-12');
        $this->dated($board, 'Due only', null, '2026-11-13');
        $this->dated($board, 'Start only', '2026-11-14', null);
        $this->dated($board, 'Undated', null, null);
        $this->dated($board, 'Other month', '2026-12-10', '2026-12-11');
        $this->login($this->owner);

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'date=2026-11-15'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="nowo-task-cal-period"]', 'Nov 2026');
        self::assertSame('3', $crawler->filter('[data-title="Both"]')->attr('data-span'));
        self::assertSame('2026-11-10', $crawler->filter('[data-title="Both"]')->ancestors()->filter('[role="gridcell"]')->attr('data-date'));
        self::assertSame('2026-11-13', $crawler->filter('[data-title="Due only"]')->ancestors()->filter('[role="gridcell"]')->attr('data-date'));
        self::assertSame('2026-11-14', $crawler->filter('[data-title="Start only"]')->ancestors()->filter('[role="gridcell"]')->attr('data-date'));
        self::assertCount(0, $crawler->filter('[data-title="Undated"]'));
        self::assertCount(0, $crawler->filter('[data-title="Other month"]'));
        self::assertSelectorTextContains('[data-testid="nowo-task-cal-undated"]', '1');
        // Weeks start on Monday by default, and 2026-11-01 is a Sunday: the grid opens on 26 October.
        self::assertSame('2026-10-26', $crawler->filter('[role="gridcell"]')->first()->attr('data-date'));
    }

    public function testABarAcrossAWeekBoundaryIsDrawnInBothWeeks(): void
    {
        $board = $this->board();
        // 2026-11-07 is a Saturday, 2026-11-10 a Tuesday.
        $this->dated($board, 'Long', '2026-11-07', '2026-11-10');
        $this->login($this->owner);

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'date=2026-11-15'));

        $chips = $crawler->filter('[data-title="Long"].nowo-task-cal__chip');
        self::assertCount(2, $chips);
        self::assertSame('2', $chips->eq(0)->attr('data-span'));
        self::assertSame('2', $chips->eq(1)->attr('data-span'));
        self::assertStringContainsString('is-continuing', (string) $chips->eq(0)->attr('class'));
        self::assertStringContainsString('is-continued', (string) $chips->eq(1)->attr('class'));
        // Only the real start and the real due date can be dragged.
        self::assertCount(1, $chips->eq(0)->filter('[data-edge="start"]'));
        self::assertCount(0, $chips->eq(0)->filter('[data-edge="due"]'));
        self::assertCount(0, $chips->eq(1)->filter('[data-edge="start"]'));
        self::assertCount(1, $chips->eq(1)->filter('[data-edge="due"]'));
    }

    public function testTheWeekFollowsTheWeekStart(): void
    {
        self::ensureKernelShutdown();
        self::$taskBoardConfig = self::CONFIG + ['gantt' => ['week_start' => 'sunday']];
        $this->setUp();
        $board = $this->board();
        $this->login($this->owner);

        // 2026-11-11 is a Wednesday.
        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'mode=week&date=2026-11-11'));

        self::assertSame('2026-11-08', $crawler->filter('[role="gridcell"]')->first()->attr('data-date'));
        self::assertCount(7, $crawler->filter('[role="gridcell"]'));
        self::assertSame('Sun', trim($crawler->filter('[role="columnheader"]')->first()->text()));
    }

    public function testListModeShowsOnlyTheDaysWithTasksInOrder(): void
    {
        $board = $this->board();
        $this->dated($board, 'Later', null, '2026-11-20');
        $this->dated($board, 'Span', '2026-11-03', '2026-11-04');
        $this->login($this->owner);

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'mode=list&date=2026-11-15'));

        self::assertSame(['2026-11-03', '2026-11-04', '2026-11-20'], $crawler->filter('.nowo-task-cal__list-day')->each(static fn (Crawler $day): ?string => $day->attr('data-date')));
        self::assertSelectorExists('#nowo-task-cal-day-2026-11-20');
    }

    public function testAnImpossibleDateOrModeFallsBackToTheDefault(): void
    {
        $board = $this->board();
        $this->login($this->owner);

        foreach (['date=2026-13-45', 'date=99999-01-01', 'date[]=x', 'mode=x', 'mode[]=x', 'assignee=9999&column=x&priority=x'] as $query) {
            $this->client->request('GET', $this->calendarUrl($board, $query));
            self::assertResponseIsSuccessful($query);
        }
    }

    public function testFiltersComeFromTheUrlAndBoundTheQuery(): void
    {
        $board  = $this->board();
        $other  = $this->user('other@example.com');
        $parent = $this->dated($board, 'Parent', null, '2026-11-10');
        $this->dated($board, 'Sub', null, '2026-11-11', $parent);
        $high = $this->dated($board, 'High', null, '2026-11-12', column: 1);
        $high->setPriority(TaskPriority::High);
        $high->setTags(['alpha']);
        $done = $this->dated($board, 'Done', null, '2026-11-13');
        $done->markCompleted(new DateTimeImmutable('2026-11-13'));
        $assigned = $this->dated($board, 'Assigned', null, '2026-11-14');
        $this->em->persist(new TaskMember($assigned, $other, TaskMemberRole::Assignee));
        $this->em->flush();
        $this->login($this->owner);

        $titles = function (string $query) use ($board): array {
            $crawler = $this->client->request('GET', $this->calendarUrl($board, 'date=2026-11-15&' . $query));
            self::assertResponseIsSuccessful();

            return $crawler->filter('.nowo-task-cal__chip')->each(static fn (Crawler $chip): ?string => $chip->attr('data-title'));
        };

        self::assertSame(['Parent', 'High', 'Assigned'], $titles(''));
        self::assertSame(['Parent', 'Sub', 'High', 'Assigned'], $titles('subtasks=1'));
        self::assertSame(['Parent', 'High', 'Done', 'Assigned'], $titles('completed=1'));
        self::assertSame(['High'], $titles('priority=high'));
        self::assertSame(['High'], $titles('tag=ALPHA'));
        self::assertSame([], $titles('tag=none'));
        self::assertSame(['High'], $titles('column=' . $board->getColumns()->get(1)?->getId()));
        self::assertSame(['Assigned'], $titles('assignee=' . $other->getId()));
        // A person who is on no task of the board is not a filter.
        self::assertSame(['Parent', 'High', 'Assigned'], $titles('assignee=999999'));

        // Links keep the filter.
        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'date=2026-11-15&priority=high&mode=week'));
        self::assertStringContainsString('priority=high', (string) $crawler->filter('.nowo-task-cal__nav a[rel="next"]')->attr('href'));
    }

    public function testACappedRangeSaysSoAndHowToNarrowIt(): void
    {
        self::ensureKernelShutdown();
        self::$taskBoardConfig = self::CONFIG + ['board' => ['max_tasks' => 2]];
        $this->setUp();
        $board = $this->board();
        $this->dated($board, 'A', null, '2026-11-10');
        $this->dated($board, 'B', null, '2026-11-11');
        $this->dated($board, 'C', null, '2026-11-12');
        $this->login($this->owner);

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'date=2026-11-15'));

        self::assertCount(2, $crawler->filter('.nowo-task-cal__chip'));
        self::assertSelectorTextContains('[data-testid="task-board-window-notice"]', '3');
        self::assertSelectorExists('.nowo-task-board__window-notice:not([data-testid])');
    }

    public function testADayWithMoreBarsThanRoomLinksToTheRest(): void
    {
        $board = $this->board();
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $title) {
            $this->dated($board, $title, null, '2026-11-10');
        }
        $this->login($this->owner);

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'date=2026-11-15'));

        self::assertCount(4, $crawler->filter('.nowo-task-cal__chip'));
        self::assertStringContainsString('2', $crawler->filter('.nowo-task-cal__more')->text());
        self::assertStringContainsString('mode=list', (string) $crawler->filter('.nowo-task-cal__more')->attr('href'));
    }

    /** FR-028: without the right to edit a chip has no handle and no action. */
    public function testAReaderSeesTheSameCalendarAndNothingToDrag(): void
    {
        $board = $this->board();
        $this->dated($board, 'Task', '2026-11-10', '2026-11-12');
        $reader = $this->user('reader@example.com');
        $this->login($reader);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (object $event): void {
            $event->grant();
            $event->markReadOnly();
        });
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->grant();
            $event->markReadOnly();
        });

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'date=2026-11-15'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.nowo-task-cal__chip'));
        self::assertSelectorNotExists('[data-nowo-ui-sortable]');
        self::assertSelectorNotExists('[data-nowo-ui-sortable-item]');
        self::assertSelectorNotExists('[data-edge]');
        self::assertSelectorNotExists('.nowo-task-cal__reschedule');
        self::assertSelectorNotExists('.nowo-task-cal__add');
        self::assertSelectorNotExists('#nowo-task-board-add-task-modal');
        self::assertSelectorNotExists('#nowo-task-cal-reschedule');
    }

    public function testAnOutsiderGetsNoCalendar(): void
    {
        $board = $this->board();
        $this->login($this->user('outsider@example.com'));
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (object $event): void {
            $event->deny();
        });

        $this->client->request('GET', $this->calendarUrl($board));

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheOtherViewsLinkToTheCalendar(): void
    {
        $board = $this->board();
        $this->login($this->owner);

        foreach (['?view=board', '/list', '/gantt'] as $page) {
            $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . $page);
            self::assertResponseIsSuccessful();
            self::assertGreaterThan(0, $crawler->filter('a[href="' . $this->calendarUrl($board) . '"]')->count(), $page);
        }

        $crawler = $this->client->request('GET', '/tools/task-board');
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-board-view-calendar"]'));
    }

    /** FR-024. */
    public function testTheCalendarCanBeTheDefaultView(): void
    {
        self::ensureKernelShutdown();
        self::$taskBoardConfig = self::CONFIG + ['board' => ['default_view_resolver' => 'app.default_view']];
        $this->setUp();
        $board = $this->board();
        $this->login($this->owner);
        $resolver = self::getContainer()->get('app.default_view');
        self::assertInstanceOf(PreferredViewResolver::class, $resolver);
        $resolver->view = 'calendar';

        $this->client->request('GET', '/tools/task-board/board/' . $board->getId(), server: ['HTTP_REFERER' => '']);

        self::assertResponseRedirects($this->calendarUrl($board));
    }

    public function testTheAddControlFillsTheDayAndComesBackToTheCalendar(): void
    {
        $board = $this->board();
        $this->login($this->owner);

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'date=2026-11-15'));
        self::assertSame('2026-11-10', $crawler->filter('[data-date="2026-11-10"] .nowo-task-cal__add')->attr('data-due-date'));
        $action = (string) $crawler->filter('#nowo-task-board-add-task-form')->attr('action');
        self::assertStringContainsString('calendar=1', $action);

        $form                     = $crawler->filter('#nowo-task-board-add-task-form')->form();
        $form['task_form[title]'] = 'From the calendar';
        $form['task_form[dueAt]'] = '2026-11-10';
        $this->client->submit($form);

        self::assertResponseRedirects($this->calendarUrl($board, 'date=2026-11-15'));
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'From the calendar']);
        self::assertInstanceOf(Task::class, $task);
        self::assertSame('2026-11-10', $task->getDueAt()?->format('Y-m-d'));
    }
}
