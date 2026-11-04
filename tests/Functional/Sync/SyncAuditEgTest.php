<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function ceil;
use function count;

/**
 * Since 1.23 — regressions of the 1.23 audit (Appendix EG): one slice of a run at a time (EG-L1), a task list that
 * never ends (EG-L2), a list larger than its task count (EG-L7), and the host's credential page link (EG-I10).
 */
final class SyncAuditEgTest extends SyncFunctionalTestCase
{
    /** EG-L1: a second request while a slice of the same run holds the lock is Busy and writes nothing. */
    public function testASecondSliceOfARunningRunIsBusy(): void
    {
        $board = $this->board();
        $small = new InMemoryTaskProvider(pageSize: 1);
        $small->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false));
        foreach ([1, 2, 3] as $i) {
            $small->putTask(self::LIST, $this->remote('b' . $i, 'Task ' . $i, $i));
        }
        $first = $this->sync($board, $small, maxPages: 1);
        self::assertSame(SyncStatus::Paused, $first->status);

        // Slice A of that run is in flight: the lock holds the run's id with a fresh heartbeat.
        $this->em->createQuery('UPDATE ' . TaskBoardConnection::class . ' c SET c.runningRunId = :run, c.heartbeatAt = :now')
            ->setParameter('run', $first->runId)
            ->setParameter('now', new DateTimeImmutable(), 'datetime_immutable')
            ->execute();
        $calls  = count($small->calls);
        $second = $this->sync($board, $small, maxPages: 1);

        self::assertSame(SyncStatus::Busy, $second->status);
        self::assertSame($calls, count($small->calls), 'no provider call');
        self::assertCount(1, $this->tasksOf($board), 'nothing written');
        $this->em->clear();
        self::assertSame($first->runId, $this->connectionOf($board)?->getRunningRunId(), 'slice A keeps its lock');
    }

    /** EG-L2: a page that brings no new task ends the list; a list that keeps giving new tasks stops at the page cap. */
    public function testAListThatNeverEndsIsStopped(): void
    {
        $board = $this->board();
        $same  = $this->sync($board, new RepeatingProvider($this->provider, repeat: false, endless: 'same'));
        self::assertSame(SyncStatus::Succeeded, $same->status);
        self::assertSame(2, $same->pagesDone, 'the second page brought nothing new');
        self::assertContains('paging_stopped', array_map(static fn (SyncError $w): string => $w->code, $same->warnings));

        $empty = $this->sync($board, new RepeatingProvider($this->provider, repeat: false, endless: 'empty'), full: true);
        self::assertSame(1, $empty->pagesDone);

        // max_tasks_per_run 5000: a slice reads at most ceil(5000 / 100) + 1 = 51 pages.
        $endless = $this->sync($board, new RepeatingProvider($this->provider, repeat: false, endless: 'new'), full: true);
        self::assertSame(SyncStatus::Failed, $endless->status);
        self::assertSame('response_too_large', $endless->errors[0]->code);
        self::assertSame(51, $endless->pagesDone);
    }

    /**
     * EG-L7: a list whose task count leaves out subtasks and closed tasks (the list says 3, the pages hold 330 or 600
     * rows) is read in full: no cap comes from the count, only `max_pages_per_run`.
     *
     * @return iterable<string, array{0: int}>
     */
    public static function listSizes(): iterable
    {
        yield '330 rows' => [330];
        yield '600 rows' => [600];
    }

    #[DataProvider('listSizes')]
    public function testAListLargerThanItsTaskCountIsReadInFull(int $rows): void
    {
        $board = $this->board();
        $big   = new InMemoryTaskProvider();
        $big->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false, 3));
        for ($i = 1; $i <= $rows; ++$i) {
            $big->putTask(self::LIST, $this->remote('r' . $i, 'Task ' . $i, $i, parent: $i > 30 ? 'r' . (($i % 30) + 1) : null));
        }

        $report = $this->sync($board, $big);

        self::assertSame(SyncStatus::Succeeded, $report->status);
        self::assertSame($rows, $report->created);
        self::assertSame((int) ceil($rows / 100), $report->pagesDone);
        $this->em->clear();
        self::assertNotNull($this->connectionOf($board)?->getWatermark(), 'the next run is incremental');
    }

    /** EG-I10: the host's credential page is linked only when it is an https URL or a same-origin path. */
    public function testTheCredentialPageLinkIsFiltered(): void
    {
        $board = $this->board();
        $this->login($this->owner);
        foreach (['javascript:alert(1)' => false, '//evil.test/x' => false, '/\\evil.test' => false, 'http://h.test/x' => false, 'https://h.test/tokens?x=1' => true, '/settings/tokens' => true] as $url => $shown) {
            TestCredentialResolver::$formUrl = $url;
            $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/connection?provider=clickup');
            self::assertResponseIsSuccessful();
            $crawler = $this->client->getCrawler();
            self::assertSame($shown, $crawler->filterXPath('//a[@href="' . $url . '"]')->count() > 0, $url);
        }
    }
}
