<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateInterval;
use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Event\PeopleMappingChangedEvent;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTimeEntry;
use Nowo\TaskBoardBundle\Sync\Time\ImportedTimeEntryCandidate;
use Nowo\TaskBoardBundle\Sync\View\SyncPeopleViewProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\AttributionPeopleResolver;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingTimeSink;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedPeopleResolver;

use function array_map;
use function array_merge;
use function array_replace_recursive;
use function json_encode;

/**
 * Since 1.28.0 (TB-SYNC-4, decision D-20261106-10): hours of people with no user reach a sink that takes them; the bulk
 * "former person" action; a person attributed to a host user who gets no access.
 */
final class FormerPeopleTest extends SyncPeopleFunctionalTestCase
{
    private const BASE = '/tools/task-board/board/';

    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => [
            'people'             => ['resolver' => 'app.people_resolver_attribution'],
            'people_bulk_former' => true,
            'time_entries'       => ['sink' => 'app.time_sink_ownerless'],
        ]]);
    }

    protected function setUp(): void
    {
        ScriptedPeopleResolver::reset();
        AttributionPeopleResolver::$attributionOnly = [];
        RecordingTimeSink::reset();
        parent::setUp();
    }

    public function testTheHoursOfAPersonWithNoUserReachTheSinkWithThePerson(): void
    {
        $board                              = $this->boardWithColumns('To do');
        $user                               = $this->viewer('kept@example.test');
        ScriptedPeopleResolver::$candidates = ['kept@example.test'];
        ScriptedPeopleResolver::$verified   = ['kept@example.test'];
        $this->workspace([
            new RemotePerson('1', 'Kept', 'kept@example.test', RemoteRole::Member),
            new RemotePerson('2', 'Gone Person', 'gone@remote.test', RemoteRole::Member),
        ]);
        $this->put($this->row('t1', 'Task', 1));
        $now = new DateTimeImmutable();
        $this->provider->withTimeEntries([
            $this->entry('e1', self::ref('1', 'kept@example.test', 'Kept'), 't1', $now->sub(new DateInterval('P2D'))),
            $this->entry('e2', self::ref('2', 'gone@remote.test', 'Gone Person'), 't1', $now->sub(new DateInterval('P2D'))),
        ]);

        $report = $this->sync($board, timeEntries: true, timeFrom: $now->sub(new DateInterval('P5D')));

        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame(0, $report->time->skippedUnmapped);
        self::assertSame(2, $report->time->entries);
        self::assertSame(1, $report->stats['ownerless.entries'] ?? null);
        $delivered = [];
        foreach (array_merge(...array_map(static fn (array $call): array => $call['entries'], RecordingTimeSink::$calls)) as $candidate) {
            self::assertInstanceOf(ImportedTimeEntryCandidate::class, $candidate);
            $delivered[$candidate->entry->id] = $candidate;
        }
        self::assertFalse($delivered['e1']->isOwnerless());
        self::assertSame($user->getEmail(), $delivered['e1']->user() instanceof AppUser ? $delivered['e1']->user()->getEmail() : null);
        self::assertTrue($delivered['e2']->isOwnerless());
        self::assertNull($delivered['e2']->user());
        self::assertSame('Gone Person', $delivered['e2']->personName());
        // The reference TimeTrackBundle 1.15 ImportedTimeEntryPerson::REF_PATTERN accepts.
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._:#-]{1,64}$/D', $delivered['e2']->person->getId());

        $delivered['e2']->person->anonymise(new DateTimeImmutable());
        self::assertNull($delivered['e2']->personName(), 'an anonymised person hands over no name');
    }

    public function testTheBulkActionMarksOnlyUndecidedPeopleAndStaysChangeable(): void
    {
        $board                              = $this->boardWithColumns('To do');
        $ana                                = $this->viewer('ana@example.com');
        ScriptedPeopleResolver::$candidates = ['ana@example.com'];
        $events                             = [];
        $this->dispatcher()->addListener(TaskBoardEvents::SYNC_PEOPLE_MAPPING_CHANGED, static function (PeopleMappingChangedEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $this->workspace([
            new RemotePerson('1', 'One', 'one@remote.test', RemoteRole::Member),
            new RemotePerson('2', 'Two', 'two@remote.test', RemoteRole::Member),
            new RemotePerson('3', 'Three', 'three@remote.test', RemoteRole::Member),
        ]);
        $this->put($this->row('t1', 'One task', 1, assignees: [self::ref('1', 'one@remote.test', 'One'), self::ref('2', 'two@remote.test', 'Two'), self::ref('3', 'three@remote.test', 'Three')]));
        $this->sync($board);
        $this->login($this->owner);
        $mapped = $this->freshPerson('3');
        $this->save($board, [$mapped->getId() => (string) $ana->getId()]);
        $events = [];

        $page = $this->client->request('GET', self::BASE . $board->getId() . '/connection/people');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-testid="nowo-task-sync-people-former"]'));

        // CSRF: its own token.
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/people/former', ['task_board_sync_people_former' => ['_token' => PredictableCsrfTokenManager::value('task_board_connection_people')]]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(TaskSyncPersonMapping::Unmatched, $this->freshPerson('1')->getMapping());

        $this->former($board);
        self::assertResponseRedirects(self::BASE . $board->getId() . '/connection/people');
        self::assertSame(TaskSyncPersonMapping::External, $this->freshPerson('1')->getMapping());
        self::assertSame(TaskSyncPersonMapping::External, $this->freshPerson('2')->getMapping());
        self::assertSame(TaskSyncPersonMapping::Manual, $this->freshPerson('3')->getMapping(), 'a mapped person stays');
        self::assertCount(1, $events);
        self::assertCount(2, $events[0]->changes);
        self::assertSame('unmatched', $events[0]->changes[0]['previousMapping']);

        // Nothing left: no button, and a repeat changes nothing.
        $page = $this->client->request('GET', self::BASE . $board->getId() . '/connection/people');
        self::assertCount(0, $page->filter('[data-testid="nowo-task-sync-people-former"]'));
        $this->former($board);
        self::assertCount(1, $events);

        // The sync never overrides the decision, even with a verified e-mail now.
        ScriptedPeopleResolver::$verified   = ['one@remote.test'];
        ScriptedPeopleResolver::$candidates = ['ana@example.com', 'one@remote.test'];
        $this->viewer('one@remote.test');
        $this->sync($board, full: true);
        self::assertSame(TaskSyncPersonMapping::External, $this->freshPerson('1')->getMapping());

        // Changeable person by person.
        $this->login($this->owner);
        $this->save($board, [$this->freshPerson('1')->getId() => (string) $ana->getId()]);
        self::assertSame(TaskSyncPersonMapping::Manual, $this->freshPerson('1')->getMapping());
    }

    public function testAStrangerCannotUseTheBulkAction(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->workspace([new RemotePerson('1', 'One', 'one@remote.test', RemoteRole::Member)]);
        $this->put($this->row('t1', 'One task', 1, assignees: [self::ref('1', 'one@remote.test', 'One')]));
        $this->sync($board);
        $this->login($this->viewer('stranger@example.test'));
        $this->former($board);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(TaskSyncPersonMapping::Unmatched, $this->freshPerson('1')->getMapping());
    }

    public function testAManagerOfOneBoardOfASharedDirectoryCannotMarkPeople(): void
    {
        ScriptedPeopleResolver::$scope = 'org:shared';
        $board                         = $this->boardWithColumns('To do');
        $this->workspace([new RemotePerson('1', 'One', 'one@remote.test', RemoteRole::Member)]);
        $this->put($this->row('t1', 'One task', 1, assignees: [self::ref('1', 'one@remote.test', 'One')]));
        $this->sync($board);
        $other      = $this->viewer('other@example.test');
        $otherBoard = $this->board('Other', ['To do'], $other);
        $this->sync($otherBoard, actor: $other);
        $this->login($this->owner);
        $this->former($board);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(TaskSyncPersonMapping::Unmatched, $this->freshPerson('1')->getMapping());
    }

    public function testAttributionToASuspendedMemberGivesNoMembershipAndNoAccess(): void
    {
        $board = $this->boardWithColumns('To do');
        // The worst case: the host's access check still says yes. The flag wins.
        $suspended = $this->viewer('suspended@example.test')->setDisplayName('Sus Pended');
        $this->em->flush();
        ScriptedPeopleResolver::$candidates         = ['suspended@example.test'];
        AttributionPeopleResolver::$attributionOnly = ['suspended@example.test'];
        $this->workspace([new RemotePerson('1', 'Remote Sus', 'sus@remote.test', RemoteRole::Member)]);
        $this->put($this->row('t1', 'Attributed', 1, creator: self::ref('1', 'sus@remote.test', 'Remote Sus'), assignees: [self::ref('1', 'sus@remote.test', 'Remote Sus')]));
        $now = new DateTimeImmutable();
        $this->provider->withTimeEntries([$this->entry('e1', self::ref('1', 'sus@remote.test', 'Remote Sus'), 't1', $now->sub(new DateInterval('P2D')))]);
        $this->sync($board);
        $this->login($this->owner);

        $page = $this->client->request('GET', self::BASE . $board->getId() . '/connection/people');
        self::assertStringContainsString('attribution only', $page->filter('option[value="' . $suspended->getId() . '"]')->text());

        $this->save($board, [$this->freshPerson('1')->getId() => (string) $suspended->getId()]);
        self::assertSame(TaskSyncPersonMapping::Manual, $this->freshPerson('1')->getMapping());
        $this->sync($board, full: true, timeEntries: true, timeFrom: $now->sub(new DateInterval('P5D')));

        $this->em->clear();
        $this->refreshOwner();
        $task = $this->taskTitled('Attributed');
        self::assertCount(0, $task->getMembers(), 'no membership row');
        self::assertSame(0, $this->rows(TaskMember::class));
        self::assertFalse($task->getCreator() instanceof AppUser && $task->getCreator()->getEmail() === 'suspended@example.test', 'never written as creator (a creator gets access)');

        $views = self::getContainer()->get(SyncPeopleViewProvider::class);
        self::assertInstanceOf(SyncPeopleViewProvider::class, $views);
        $view = $views->creatorOf($task);
        self::assertSame('Sus Pended', $view?->name);
        self::assertTrue($view->attributionOnly);
        self::assertFalse($view->boardAccess);
        self::assertTrue($view->external);

        // Time is attributed to the user.
        $delivered = array_merge(...array_map(static fn (array $call): array => $call['entries'], RecordingTimeSink::$calls));
        self::assertNotSame([], $delivered);
        $candidate = $delivered[0];
        self::assertInstanceOf(ImportedTimeEntryCandidate::class, $candidate);
        self::assertSame('suspended@example.test', $candidate->user() instanceof AppUser ? $candidate->user()->getEmail() : null);
    }

    public function testWithoutTheHostFlagTheViewIsWhatItWas(): void
    {
        $board  = $this->boardWithColumns('To do');
        $viewer = $this->viewer('plain@example.test');
        $viewer->setDisplayName('Plain Host');
        $this->em->flush();
        ScriptedPeopleResolver::$candidates = ['plain@example.test'];
        $this->workspace([new RemotePerson('1', 'Remote Name', 'r@remote.test', RemoteRole::Member)]);
        $this->put($this->row('t1', 'Plain', 1, creator: self::ref('1', 'r@remote.test', 'Remote Name')));
        $this->sync($board);
        $this->login($this->owner);
        $this->save($board, [$this->freshPerson('1')->getId() => (string) $viewer->getId()]);
        $this->em->clear();
        $this->refreshOwner();
        $task  = $this->taskTitled('Plain');
        $views = self::getContainer()->get(SyncPeopleViewProvider::class);
        self::assertInstanceOf(SyncPeopleViewProvider::class, $views);
        $view = $views->creatorOf($task);
        self::assertInstanceOf(Task::class, $task);
        self::assertSame('Remote Name', $view?->name);
        self::assertFalse($view->attributionOnly);
    }

    private function entry(string $id, RemotePersonRef $person, string $task, DateTimeImmutable $start): RemoteTimeEntry
    {
        return new RemoteTimeEntry($id, $task, $person, $start, $start->add(new DateInterval('PT1H')), 3600, false);
    }

    /**
     * @param array<string, string> $choices person id => value
     */
    private function save(TaskBoard $board, array $choices): void
    {
        $fields = ['_token' => PredictableCsrfTokenManager::value('task_board_connection_people')];
        foreach ($choices as $id => $value) {
            $fields['p_' . $id] = $value;
        }
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/people/save?filter=all&page=1', ['task_board_sync_people' => $fields]);
    }

    private function former(TaskBoard $board): void
    {
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/people/former', ['task_board_sync_people_former' => ['_token' => PredictableCsrfTokenManager::value('task_board_connection_people_former')]]);
    }

    private function freshPerson(string $providerUserId): TaskSyncPerson
    {
        $this->em->clear();
        $this->refreshOwner();

        return $this->personOf($providerUserId);
    }
}
