<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Calendar;

use Nowo\TaskBoardBundle\Calendar\CalendarAnnotations;
use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotation;
use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotations;
use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotator;
use Nowo\TaskBoardBundle\Calendar\CalendarDayKind;
use Nowo\TaskBoardBundle\Calendar\CalendarTaskWarning;
use Nowo\TaskBoardBundle\Controller\TaskBoardManageController;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Dto\TaskCalendarDay;
use Nowo\TaskBoardBundle\Dto\TaskCalendarEntry;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Service\TaskCalendarBuilder;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedCalendarDays;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

use function preg_replace;

/**
 * Since 2.14.0 (Beacon spec 204): the host's working days, holidays and leave on a board's calendar. Without a provider the
 * page is the 2.13 page; with one, the viewer's days are shaded and labelled, a task whose assignee is off on one of its days
 * carries a warning, every label is escaped, and the provider is asked once for the viewer and once for all assignees.
 */
#[CoversClass(CalendarDayAnnotator::class)]
#[CoversClass(CalendarAnnotations::class)]
#[CoversClass(CalendarDayAnnotations::class)]
#[CoversClass(CalendarDayAnnotation::class)]
#[CoversClass(CalendarTaskWarning::class)]
#[CoversClass(TaskCalendarBuilder::class)]
#[CoversClass(TaskCalendarDay::class)]
#[CoversClass(TaskCalendarEntry::class)]
#[CoversClass(TaskBoardManageController::class)]
#[CoversClass(TaskBoardExtension::class)]
final class CalendarDayAnnotationsTest extends CalendarTestCase
{
    private const WITH_PROVIDER = ['calendar' => ['enabled' => true, 'day_annotation_provider' => 'app.calendar_days'], 'task_numbers' => ['enabled' => true]];

    public function testWithoutAProviderOrWithNothingToSayThePageIsUnchanged(): void
    {
        $pages = [];
        foreach ([self::CONFIG, self::WITH_PROVIDER] as $config) {
            self::$taskBoardConfig = $config;
            self::ensureKernelShutdown();
            $this->setUp();
            $this->seed();
            foreach (['month', 'week', 'list'] as $mode) {
                $this->client->request('GET', $this->calendarUrl($this->em->getRepository(TaskBoard::class)->findAll()[0], 'mode=' . $mode . '&date=2030-03-04'));
                self::assertResponseIsSuccessful();
                // Since 2.14.1 (audit NN-I2) the assignee filter is sorted by label, then id: the whole page compares.
                $pages[$mode][] = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', 'ID', (string) $this->client->getResponse()->getContent());
            }
        }

        foreach ($pages as $mode => [$without, $empty]) {
            self::assertSame($without, $empty, $mode . ': a provider with nothing to say changes nothing');
            foreach (['data-non-working', 'nowo-task-cal__day-label', 'has-off-day', 'nowo-task-cal__off-day', 'skip-non-working', 'is-non-working'] as $marker) {
                self::assertStringNotContainsString($marker, $without, $mode . ': ' . $marker);
            }
        }
    }

    public function testTheProviderAnnotationsAreRenderedAndEscaped(): void
    {
        self::$taskBoardConfig = self::WITH_PROVIDER;
        self::ensureKernelShutdown();
        $this->setUp();
        [$board, $alice, $bob]                        = $this->seed();
        $days                                         = $this->days();
        $days->viewer[(string) $this->owner->getId()] = [
            new CalendarDayAnnotation('2030-03-04', true, 'Fiesta <b>local</b>', CalendarDayKind::Holiday),
            new CalendarDayAnnotation('2030-03-05', false, 'Half day'),
            new CalendarDayAnnotation('2030-03-09', true, 'Weekend', CalendarDayKind::Weekend),
        ];
        $days->people[(string) $alice->getId()] = [new CalendarDayAnnotation('2030-03-04', true, 'Absent <i>', CalendarDayKind::Absent)];
        $days->people[(string) $bob->getId()]   = [new CalendarDayAnnotation('2030-03-03', true, 'Absent', CalendarDayKind::Absent), new CalendarDayAnnotation('2030-03-12', false, 'Training')];

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'mode=month&date=2030-03-04'));

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $holiday = $crawler->filter('[role="gridcell"][data-date="2030-03-04"]');
        self::assertStringContainsString('is-non-working', (string) $holiday->attr('class'));
        self::assertStringContainsString('is-day-holiday', (string) $holiday->attr('class'));
        self::assertSame('1', $holiday->attr('data-non-working'));
        self::assertSame('Fiesta <b>local</b>', $holiday->filter('[data-testid="nowo-task-cal-day-label"]')->text());
        $half = $crawler->filter('[role="gridcell"][data-date="2030-03-05"]');
        self::assertStringNotContainsString('is-non-working', (string) $half->attr('class'));
        self::assertNull($half->attr('data-non-working'));
        self::assertSame('Half day', $half->filter('[data-testid="nowo-task-cal-day-label"]')->text());
        self::assertStringNotContainsString('<b>local</b>', $content);
        self::assertStringNotContainsString('<i>', $content);

        // Alice is off on the due date, Bob on a day of the span; Carol's task and the task of a working day carry nothing.
        self::assertSame('Absent <i>', $crawler->filter('[data-title="Due on a holiday"] [data-testid="nowo-task-cal-off-day"] .nowo-task-cal__off-day-label')->text());
        self::assertStringContainsString('2030-03-04', (string) $crawler->filter('[data-title="Due on a holiday"] [data-testid="nowo-task-cal-off-day"]')->attr('title'));
        self::assertStringContainsString('has-off-day', (string) $crawler->filter('[data-title="Due on a holiday"]')->attr('class'));
        self::assertSame('Absent', $crawler->filter('[data-title="Span over an absence"] .nowo-task-cal__off-day-label')->text());
        self::assertCount(0, $crawler->filter('[data-title="Working day"] [data-testid="nowo-task-cal-off-day"]'));
        self::assertCount(0, $crawler->filter('[data-title="Unannotated assignee"] [data-testid="nowo-task-cal-off-day"]'));
        self::assertCount(0, $crawler->filter('[data-title="Training day"] [data-testid="nowo-task-cal-off-day"]'), 'a working annotation is no warning');
        self::assertCount(0, $crawler->filter('[data-nowo-task-board-calendar-skip-non-working-value]'), 'skip_non_working_days is off');

        // One call for the viewer, one for every assignee of the page together.
        self::assertCount(2, $days->calls);
        self::assertSame(['viewer', (string) $this->owner->getId(), $board->getId(), '2030-02-25', '2030-03-31'], [$days->calls[0][0], $days->calls[0][1]->userId, $days->calls[0][1]->boardId, $days->calls[0][2]->from, $days->calls[0][2]->to]);
        self::assertSame('assignees', $days->calls[1][0]);
        $ids = $days->calls[1][3];
        sort($ids);
        self::assertCount(3, $ids);

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'mode=list&date=2030-03-04'));
        self::assertSame('Fiesta <b>local</b>', $crawler->filter('#nowo-task-cal-day-2030-03-04 [data-testid="nowo-task-cal-day-label"]')->text());
        self::assertSame('Absent <i>', $crawler->filter('#nowo-task-cal-day-2030-03-04 [data-title="Due on a holiday"] .nowo-task-cal__off-day-label')->text());

        // Two days off in one task: the first label and "+1". A week page asks only for its days: 3 March is outside it.
        $days->people[(string) $bob->getId()][] = new CalendarDayAnnotation('2030-03-05', true, 'Absent', CalendarDayKind::Absent);
        $this->client->request('GET', $this->calendarUrl($board, 'mode=month&date=2030-03-04'));
        self::assertSelectorTextContains('[data-title="Span over an absence"] .nowo-task-cal__off-day-more', '+1');
        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'mode=week&date=2030-03-04'));
        self::assertCount(0, $crawler->filter('[data-title="Span over an absence"] .nowo-task-cal__off-day-more'));
        self::assertSame('2030-03-04', $days->calls[array_key_last($days->calls)][2]->from);
    }

    /**
     * Since 2.15.1 (REQ-SEC-006): the provider's answer is read by the exact id asked. A key that is not byte for byte
     * one of the ids the bundle asked for (another spelling, a look-alike, a differently-typed key PHP's array-key
     * juggling would match) annotates nobody; the assignee answered under the exact id still carries her warning.
     */
    public function testOnlyAKeyExactlyAsAskedAnnotatesAnAssignee(): void
    {
        self::$taskBoardConfig = self::WITH_PROVIDER;
        self::ensureKernelShutdown();
        $this->setUp();
        [$board, $alice, $bob]                  = $this->seed();
        $days                                   = $this->days();
        $days->people[(string) $alice->getId()] = [new CalendarDayAnnotation('2030-03-04', true, 'Absent alice', CalendarDayKind::Absent)];
        $days->people[(string) $bob->getId()]   = [new CalendarDayAnnotation('2030-03-03', true, 'Absent bob', CalendarDayKind::Absent)];
        // Alice is answered under the int id (a host that keys its answer by `getId()`): still the id asked.
        $days->answerKeys[(string) $alice->getId()] = (int) $alice->getId();
        // Bob is answered under a key that is not the id asked: an edge space (a look-alike of his id).
        $days->answerKeys[(string) $bob->getId()] = ' ' . $bob->getId();

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'mode=month&date=2030-03-04'));

        self::assertResponseIsSuccessful();
        self::assertSame('Absent alice', $crawler->filter('[data-title="Due on a holiday"] .nowo-task-cal__off-day-label')->text(), 'the id asked, answered as an int key, still annotates');
        self::assertStringNotContainsString('Absent bob', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('[data-title="Span over an absence"] [data-testid="nowo-task-cal-off-day"]'));
    }

    /**
     * Since 2.14.1 (audit NN-L1): a provider that throws never breaks the calendar: the page renders without annotations
     * (the 2.13 page) and the failure is logged.
     */
    public function testAFailingProviderRendersThePageWithoutAnnotations(): void
    {
        self::$taskBoardConfig = self::WITH_PROVIDER;
        self::ensureKernelShutdown();
        $this->setUp();
        [$board, $alice]                              = $this->seed();
        $days                                         = $this->days();
        $days->viewer[(string) $this->owner->getId()] = [new CalendarDayAnnotation('2030-03-04', true, 'Holiday', CalendarDayKind::Holiday)];
        $days->people[(string) $alice->getId()]       = [new CalendarDayAnnotation('2030-03-04', true, 'Absent', CalendarDayKind::Absent)];
        $days->failure                                = new RuntimeException('HR service down');

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'mode=month&date=2030-03-04'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-title="Due on a holiday"]'), 'the tasks are still shown');
        $content = (string) $this->client->getResponse()->getContent();
        foreach (['data-non-working', 'nowo-task-cal__day-label', 'has-off-day', 'nowo-task-cal__off-day'] as $marker) {
            self::assertStringNotContainsString($marker, $content, $marker);
        }
    }

    public function testSkipNonWorkingDaysTellsTheDragToSnap(): void
    {
        self::$taskBoardConfig = ['calendar' => ['enabled' => true, 'day_annotation_provider' => 'app.calendar_days', 'skip_non_working_days' => true]];
        self::ensureKernelShutdown();
        $this->setUp();
        [$board] = $this->seed();

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'date=2030-03-04'));

        self::assertSame('true', $crawler->filter('.nowo-task-cal')->attr('data-nowo-task-board-calendar-skip-non-working-value'));
    }

    public function testSkipNonWorkingDaysWithoutAProviderDoesNothing(): void
    {
        self::$taskBoardConfig = ['calendar' => ['enabled' => true, 'skip_non_working_days' => true]];
        self::ensureKernelShutdown();
        $this->setUp();
        [$board] = $this->seed();

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'date=2030-03-04'));

        self::assertCount(0, $crawler->filter('[data-nowo-task-board-calendar-skip-non-working-value]'));
        self::assertFalse(self::getContainer()->has(CalendarDayAnnotator::class));
    }

    private function days(): ScriptedCalendarDays
    {
        $days = self::getContainer()->get('app.calendar_days');
        self::assertInstanceOf(ScriptedCalendarDays::class, $days);

        return $days;
    }

    /**
     * The same board and tasks in every kernel, the owner signed in.
     *
     * @return array{TaskBoard, AppUser, AppUser}
     */
    private function seed(): array
    {
        $alice = $this->user('alice@example.com');
        $bob   = $this->user('bob@example.com');
        $carol = $this->user('carol@example.com');
        $board = $this->board('Plan');
        $this->assign($this->dated($board, 'Due on a holiday', null, '2030-03-04'), $alice);
        $this->assign($this->dated($board, 'Span over an absence', '2030-03-02', '2030-03-06'), $bob);
        $this->assign($this->dated($board, 'Working day', null, '2030-03-07'), $alice);
        $this->assign($this->dated($board, 'Unannotated assignee', null, '2030-03-08'), $carol);
        $this->assign($this->dated($board, 'Training day', null, '2030-03-12'), $bob);
        $this->dated($board, 'Nobody', null, '2030-03-11');
        $this->login($this->owner);

        return [$board, $alice, $bob];
    }

    private function assign(Task $task, AppUser $user): void
    {
        $task->addMember(new TaskMember($task, $user, TaskMemberRole::Assignee));
        $this->em->flush();
    }
}
