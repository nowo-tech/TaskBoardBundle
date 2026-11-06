<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusTime;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskDetails;

/**
 * Since 1.27.0 (TB-SYNC-3, the rest): custom task id (searchable), points, done date as the completion date, time
 * flags, location, estimates per assignee, recurrence, time in status (bounded), linked tasks as related relations,
 * archived tasks imported archived, and the completeness report on the connection page. A re-sync changes nothing.
 */
final class SyncTaskDetailsTest extends SyncFunctionalTestCase
{
    private const BASE = '/tools/task-board/';

    public function testDetailsHistoryLinksAndCompleteness(): void
    {
        $board = $this->board();
        // The registered provider (the connection page reads its coverage); the run uses the one with a history.
        $this->provider->withFieldCoverage(['id' => 'imported', 'name' => 'imported', 'locations' => 'not_supported']);
        $provider = $this->historyProvider();
        $doneAt   = new DateTimeImmutable('2026-10-01T10:00:00+00:00');
        $provider->putTask(self::LIST, $this->detailed('t1', 'Ship it', 1, new RemoteTaskDetails(
            customId: 'DEV-123',
            points: '3.5',
            doneAt: $doneAt,
            closedAt: new DateTimeImmutable('2026-10-02T10:00:00+00:00'),
            startHasTime: true,
            dueHasTime: true,
            path: ['Folder <b>', 'Backlog'],
            estimates: [['person' => new RemotePersonRef('7', 'Ana'), 'minutes' => 90]],
            recurrence: 'Every week',
        ), ['id', 'name', 'locations', 'mystery_key'], done: true, linked: 't2'));
        $provider->putTask(self::LIST, $this->detailed('t2', 'Other', 2, null, ['id']));
        $provider->history['t1'] = [new RemoteStatusTime('To do', new DateTimeImmutable('@1790000000'), 60), new RemoteStatusTime('Done', null, 5, true)];
        $provider->history['t2'] = null;

        $report = $this->sync($board, $provider);
        self::assertSame(2, $report->stat('statusHistory.calls'));
        self::assertSame(2, $report->stat('keys.id'));
        self::assertSame(1, $report->stat('keys.mystery_key'));
        $task = $this->taskTitled('Ship it');
        self::assertSame($doneAt->getTimestamp(), $task->getCompletedAt()?->getTimestamp(), 'the done date is the completion date');
        $related = $this->em->getRepository(TaskDependency::class)->findAll();
        self::assertCount(1, $related);
        self::assertSame(TaskDependencyType::Related, $related[0]->getDependencyType());

        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());
        self::assertResponseIsSuccessful();
        $details = $crawler->filter('[data-testid="nowo-task-sync-details"]');
        self::assertSame('DEV-123', $details->filter('[data-sync-detail="custom-id"] code')->text());
        self::assertStringContainsString('3.5', $details->filter('[data-sync-detail="points"]')->text());
        self::assertStringContainsString('2026-10-01', $details->filter('[data-sync-detail="doneAt"]')->text());
        self::assertStringContainsString('2026-10-02', $details->filter('[data-sync-detail="closedAt"]')->text());
        self::assertStringContainsString('Start and due date', $details->filter('[data-sync-detail="times"]')->text());
        self::assertStringContainsString('Folder <b> › Backlog', $details->filter('[data-sync-detail="path"]')->text());
        // No people directory on this provider: the provider id, never the remote name.
        self::assertStringContainsString('#7: 90 min', $details->filter('[data-sync-detail="estimates"]')->text());
        self::assertStringContainsString('Every week', $details->filter('[data-sync-detail="recurrence"]')->text());
        self::assertCount(2, $details->filter('[data-testid="nowo-task-sync-status-history"] tbody tr'));
        self::assertCount(1, $details->filter('tr[aria-current="true"]'));
        self::assertStringNotContainsString('<b> ›', (string) $this->client->getResponse()->getContent(), 'remote text is escaped');

        // The custom id is searchable in the list.
        $crawler = $this->client->request('GET', self::BASE . 'board/' . $board->getId() . '/list');
        self::assertStringContainsString('DEV-123', (string) $crawler->filter('[data-title="Ship it"]')->attr('data-search-extra'));

        // The completeness report: what is imported, why not, and what is not imported yet.
        $crawler = $this->client->request('GET', self::BASE . 'board/' . $board->getId() . '/connection');
        self::assertStringContainsString('mystery_key', $crawler->filter('[data-testid="nowo-task-sync-not-imported"]')->text());
        self::assertSame('not_imported_yet', $crawler->filter('[data-completeness-key="mystery_key"]')->attr('data-completeness-status'));
        self::assertSame('not_supported', $crawler->filter('[data-completeness-key="locations"]')->attr('data-completeness-status'));
        self::assertSame('imported', $crawler->filter('[data-completeness-key="id"]')->attr('data-completeness-status'));
        self::assertSame(['mystery_key', 'locations', 'id', 'name'], $crawler->filter('[data-completeness-key]')->each(static fn ($row): string => (string) $row->attr('data-completeness-key')));

        // A re-sync changes nothing; a provider that stops serving the history keeps the last one.
        $provider->history['t1'] = null;
        $again                   = $this->sync($board, $provider, full: true);
        self::assertSame(0, $again->stat('details.updated'));
        self::assertSame(0, $again->created + $again->updated);
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $this->taskTitled('Other')->getId());
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-sync-details"]'), 'nothing to show');
    }

    private function historyProvider(): FullTaskProvider
    {
        $provider = new FullTaskProvider();
        $provider->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false, 2));
        $this->provider = $provider;

        return $provider;
    }

    /**
     * @param list<string> $keys
     */
    private function detailed(string $id, string $title, int $updated, ?RemoteTaskDetails $details, array $keys, bool $done = false, ?string $linked = null): RemoteTask
    {
        return new RemoteTask(
            task: new ImportedTaskDto(title: $title, externalId: $id, status: $done ? 'Done' : 'To do', priority: TaskPriority::Normal, dependsOn: $linked === null ? [] : [[$linked, TaskDependencyType::Related]], hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@' . (1_790_000_000 + $updated)),
            status: new RemoteStatus($done ? 'Done' : 'To do', $done ? RemoteStatusType::Closed : RemoteStatusType::Open),
            details: $details,
            presentKeys: $keys,
        );
    }
}
