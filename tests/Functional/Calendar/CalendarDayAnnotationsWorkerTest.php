<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Calendar;

use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotation;
use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotator;
use Nowo\TaskBoardBundle\Calendar\CalendarDayKind;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedCalendarDays;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function gc_collect_cycles;
use function getenv;
use function memory_get_usage;
use function putenv;

/**
 * Since 2.14.0 (REQ-RUNTIME-002): ONE kept-alive kernel built as production (`TASK_BOARD_TEST_DEBUG=0`), `http_kernel`
 * driven directly, the services resetter never called. Alice and Bob, each on a board of their own with their own day
 * labels and an absent assignee, alternate over 300 iterations of the month calendar (600 requests). Each page shows only its
 * own viewer's labels and warnings, and memory after a cycle collection grows less than 4 MiB after a 30-iteration warm-up.
 */
#[CoversClass(CalendarDayAnnotator::class)]
final class CalendarDayAnnotationsWorkerTest extends CalendarTestCase
{
    protected const CONFIG = ['calendar' => ['enabled' => true, 'day_annotation_provider' => 'app.calendar_days', 'skip_non_working_days' => true]];

    private const ITERATIONS = 300;

    private const WARM_UP = 30;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private string|false $debug = false;

    protected function setUp(): void
    {
        $this->debug = getenv('TASK_BOARD_TEST_DEBUG');
        putenv('TASK_BOARD_TEST_DEBUG=0');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv($this->debug === false ? 'TASK_BOARD_TEST_DEBUG' : 'TASK_BOARD_TEST_DEBUG=' . $this->debug);
        parent::tearDown();
    }

    public function testTwoViewersAlternateWithoutTheResetter(): void
    {
        $days = self::getContainer()->get('app.calendar_days');
        self::assertInstanceOf(ScriptedCalendarDays::class, $days);
        $boards  = [];
        $cookies = [];
        foreach (['alice', 'bob'] as $name) {
            $user  = $this->user($name . '@example.com');
            $board = $this->board(ucfirst($name) . ' board', creator: $user);
            $task  = $this->dated($board, $name . ' task', null, '2030-03-04');
            $task->addMember(new TaskMember($task, $user, TaskMemberRole::Assignee));
            $this->em->flush();
            $days->viewer[(string) $user->getId()] = [new CalendarDayAnnotation('2030-03-0' . ($name === 'alice' ? '5' : '6'), true, 'Holiday of ' . $name, CalendarDayKind::Holiday)];
            $days->people[(string) $user->getId()] = [new CalendarDayAnnotation('2030-03-04', true, 'Off ' . $name, CalendarDayKind::Absent)];
            $boards[$name]                         = $board->getId();
            $cookies[$name]                        = $this->sessionOf($user);
        }
        $http = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);

        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            foreach ($cookies as $name => $session) {
                $other    = $name === 'alice' ? 'bob' : 'alice';
                $request  = Request::create('/tools/task-board/board/' . $boards[$name] . '/calendar?date=2030-03-04', 'GET', [], $session);
                $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
                self::assertSame(200, $response->getStatusCode(), 'iteration ' . $i);
                $content = (string) $response->getContent();
                $http->terminate($request, $response);
                self::assertStringContainsString('Holiday of ' . $name, $content, 'iteration ' . $i);
                self::assertStringContainsString('Off ' . $name, $content, 'iteration ' . $i);
                self::assertStringNotContainsString('Holiday of ' . $other, $content, 'iteration ' . $i);
                self::assertStringNotContainsString('Off ' . $other, $content, 'iteration ' . $i);
            }
            $days->calls             = [];
            $this->logger()->records = [];
            // Rule 7 of REQ-RUNTIME-002: a host without the resetter clears its own entity manager at every boundary.
            $this->em->clear();
            if ($i === self::WARM_UP) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }

        gc_collect_cycles();
        self::assertLessThan(self::MEMORY_MARGIN, memory_get_usage() - $baseline, 'memory stays flat');
    }

    /**
     * @return array<string, string>
     */
    private function sessionOf(AppUser $user): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        $cookies = [];
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $cookies;
    }
}
