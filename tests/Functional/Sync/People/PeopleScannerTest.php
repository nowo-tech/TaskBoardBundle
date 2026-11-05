<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\People;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\People\PeopleMappingDeniedException;
use Nowo\TaskBoardBundle\Sync\People\PersonMappingDecision;
use Nowo\TaskBoardBundle\Sync\People\ScannedPerson;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleScanner;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklist;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklistItem;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocation;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocationKind;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTimeEntry;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccessMode;
use Nowo\TaskBoardBundle\Sync\SourceScanResult;
use Nowo\TaskBoardBundle\Sync\TaskSyncSourceScanner;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;
use Symfony\Component\Clock\MockClock;

/**
 * Since 1.25 (§2.2, §15.1): the bounded people pre-scan and the source pre-scan of the connection dialog.
 */
final class PeopleScannerTest extends PeopleFunctionalTestCase
{
    private const WS = '9000001';

    private const NOW = '2026-11-01 12:00:00';

    public function testAListScanCountsEveryKindMatchesWithoutWritingAndChecksBoardAccess(): void
    {
        $board                   = $this->board();
        $alice                   = $this->user('user1@example.test');
        $bob                     = $this->user('user2@example.test');
        $this->resolver->matches = ['user1@example.test' => [$this->owner, true], 'user2@example.test' => [$bob, false]];
        $this->script($this->provider);

        $result = $this->scanner()->scan($board, $this->provider, $this->credential(), self::WS, RemoteLocation::list(self::LIST), $this->owner);

        self::assertTrue($result->partial);
        self::assertNull($result->directoryId, 'nothing is written without persist');
        self::assertSame(TimeAccessMode::Admin, $result->timeAccess?->mode);
        self::assertSame(1 + 1 + 2 + 2 + 1, $result->calls, 'members, one task page, two comment pages, time access, one time window');
        $people = $this->byId($result->people);
        self::assertSame(['9001', '9002', '9003', '9004', '9005', '9006'], array_map(strval(...), array_keys($people)));
        self::assertSame(['member' => 1, 'creator' => 1, 'comment' => 1, 'time' => 1], $people['9001']->counts);
        self::assertSame('owner', $people['9001']->role, 'the member row (with its role) wins over refs');
        self::assertSame('User 1', $people['9001']->name);
        self::assertSame(ScannedPerson::MATCH_AUTO, $people['9001']->match);
        self::assertSame((string) $this->owner->getId(), $people['9001']->matchedUserId);
        self::assertTrue($people['9001']->boardAccess);
        self::assertNull($people['9001']->personId);
        self::assertNull($people['9001']->mapping);
        self::assertSame(ScannedPerson::MATCH_SUGGESTED, $people['9002']->match);
        self::assertFalse($people['9002']->boardAccess, 'mapped to someone who cannot view the board: the dialog warns');
        self::assertSame(['member' => 1, 'assignee' => 1], $people['9002']->counts);
        self::assertSame(['watcher' => 1], $people['9003']->counts);
        self::assertSame(ScannedPerson::MATCH_NONE, $people['9003']->match);
        self::assertNull($people['9003']->boardAccess);
        self::assertNull($people['9003']->role);
        self::assertSame(['checklist' => 1], $people['9004']->counts);
        self::assertSame(['comment' => 1], $people['9005']->counts);
        self::assertSame(['member' => 1, 'time' => 1], $people['9006']->counts);
        self::assertSame([], $this->repository()->forDirectory($this->directories()->directoryForDraft($board, 'clickup', self::WS)));
        self::assertNotContains($alice, [$people['9001']->matchedUserId]);
    }

    public function testAPersistedScanFillsTheDraftDirectoryAndAPlainOneReadsIt(): void
    {
        $board                   = $this->board();
        $bob                     = $this->user('user2@example.test');
        $this->resolver->users   = [$bob];
        $this->resolver->matches = ['user1@example.test' => [$this->owner, true], 'user2@example.test' => [$bob, false]];
        $this->script($this->provider);

        $result = $this->scanner()->scan($board, $this->provider, $this->credential(), self::WS, RemoteLocation::list(self::LIST), $this->owner, true);

        self::assertNotNull($result->directoryId);
        $people = $this->byId($result->people);
        self::assertNotNull($people['9001']->personId);
        self::assertSame('auto', $people['9001']->mapping);
        self::assertSame('suggested', $people['9002']->mapping);
        self::assertSame(ScannedPerson::MATCH_SUGGESTED, $people['9002']->match);
        self::assertSame('unmatched', $people['9003']->mapping);
        $person = $this->em->find(TaskSyncPerson::class, (string) $people['9001']->personId);
        self::assertInstanceOf(TaskSyncPerson::class, $person);
        self::assertTrue($person->hasSeenKind(TaskSyncPerson::KIND_TIME_ENTRY));
        self::assertTrue($person->hasSeenKind(TaskSyncPerson::KIND_CREATOR));

        // A later plain scan reads the stored decisions (a human mapped 9003 to the owner).
        $directory             = $this->directories()->directoryForDraft($board, 'clickup', self::WS);
        $this->resolver->users = [$this->owner, $bob];
        $this->directories()->applyMapping($directory, [new PersonMappingDecision((string) $people['9003']->personId, PersonMappingDecision::MANUAL, (string) $this->owner->getId())], $this->owner, $board);
        $again = $this->byId($this->scanner()->scan($board, $this->provider, $this->credential(), self::WS, RemoteLocation::list(self::LIST), $this->owner)->people);
        self::assertSame('manual', $again['9003']->mapping);
        self::assertSame(ScannedPerson::MATCH_AUTO, $again['9003']->match);
        self::assertTrue($again['9003']->boardAccess);
        self::assertSame(ScannedPerson::MATCH_SUGGESTED, $again['9002']->match);
        self::assertSame(ScannedPerson::MATCH_NONE, $again['9006']->match);
        self::assertSame(TaskSyncPersonMapping::Unmatched->value, $again['9006']->mapping);
    }

    public function testMemberTokensNoTimeWindowAndFolderSources(): void
    {
        $board    = $this->board();
        $provider = new InMemoryTaskProvider(pageSize: 1);
        $this->script($provider, new TimeAccess(TimeAccessMode::Member, '9001'));
        $provider->addSource(new RemoteSource(new RemoteLocation(RemoteLocationKind::Folder, '77'), 'Folder', self::WS), [self::LIST]);

        $result = $this->scanner(maxTaskPages: 5, maxCommentTasks: 0)->scan($board, $provider, $this->credential(), self::WS, new RemoteLocation(RemoteLocationKind::Folder, '77'), $this->owner);

        self::assertSame(TimeAccessMode::Member, $result->timeAccess?->mode);
        self::assertSame(1 + 1 + 3 + 2, $result->calls, 'members, the source, three pages (one task each), time access; no window for a member');
        self::assertArrayNotHasKey('9006', $this->byId($result->people));
        self::assertArrayNotHasKey('9005', $this->byId($result->people), 'no comment budget');

        // An admin token with the time window switched off reads no time either; an unknown source reads no task.
        $this->script($this->provider);
        $admin = $this->scanner(timeWindow: false)->scan($board, $this->provider, $this->credential(), self::WS, new RemoteLocation(RemoteLocationKind::Space, '404'), $this->owner);
        self::assertSame(1 + 1 + 2, $admin->calls);
        self::assertSame(['9001', '9002', '9006'], array_map(strval(...), array_keys($this->byId($admin->people))));
    }

    public function testRefusalsAndProvidersWithoutPeople(): void
    {
        $board   = $this->board();
        $mallory = $this->user('mallory@example.test');
        $plain   = $this->scanner()->scan($board, new PlainTaskProvider($this->provider), $this->credential(), self::WS, RemoteLocation::list(self::LIST), $this->owner);
        self::assertSame([], $plain->people);
        self::assertSame(0, $plain->calls);
        self::assertNull($plain->timeAccess);

        // A people provider without tracked time reads no window even with an admin token.
        $this->script($this->provider);
        $noTime = $this->scanner()->scan($board, new PeopleOnlyProvider($this->provider), $this->credential(), self::WS, RemoteLocation::list(self::LIST), $this->owner);
        self::assertSame(1 + 1 + 2 + 2, $noTime->calls);

        $this->expectException(PeopleMappingDeniedException::class);
        $this->scanner()->scan($board, $this->provider, $this->credential(), self::WS, RemoteLocation::list(self::LIST), $mallory);
    }

    public function testTheSourceScanReturnsTheWorkspaceStatusesPeopleAndTimeMode(): void
    {
        $board = $this->board();
        $this->script($this->provider);
        $statuses = [new RemoteStatus('Open', RemoteStatusType::Open), new RemoteStatus('Closed', RemoteStatusType::Closed, '#00ff00', 1)];
        $this->provider->addSource(new RemoteSource(new RemoteLocation(RemoteLocationKind::Space, '5'), 'Space 5'), [self::LIST]);
        $this->provider->addSource(new RemoteSource(RemoteLocation::list(self::LIST), 'Backlog', self::WS), [self::LIST]);
        $this->provider->withStatuses('s:5', $statuses);

        $space = $this->sourceScanner()->scan($board, $this->provider, $this->credential(), new RemoteLocation(RemoteLocationKind::Space, '5'), $this->owner);
        self::assertSame('Space 5', $space->source->name);
        self::assertSame(self::WS, $space->workspaceId, 'looked up when the source does not say');
        self::assertSame($statuses, $space->statuses);
        self::assertSame(TimeAccessMode::Admin, $space->timeAccess?->mode);
        self::assertNotNull($space->people->directoryId, 'the draft directory is persisted by default');
        self::assertSame(3 + $space->people->calls, $space->calls);

        $list = $this->sourceScanner()->scan($board, $this->provider, $this->credential(), RemoteLocation::list(self::LIST), $this->owner, false);
        self::assertSame(self::WS, $list->workspaceId);
        self::assertSame([], $list->statuses);
        self::assertSame(2 + $list->people->calls, $list->calls);

        // A provider without sources: the 1.23 container lookup, lists only.
        $plain = new PeopleOnlyProvider($this->provider);
        $old   = $this->sourceScanner()->scan($board, $plain, $this->credential(), RemoteLocation::list(self::LIST), $this->owner, false);
        self::assertSame('Backlog', $old->source->name);
        self::assertSame(['Space 1'], $old->source->path);
        self::assertSame([], $old->statuses);
        self::assertNotSame([], $old->people->people);
        $this->assertNotFound(fn (): SourceScanResult => $this->sourceScanner()->scan($board, $plain, $this->credential(), RemoteLocation::list('nope'), $this->owner));
        $this->assertNotFound(fn (): SourceScanResult => $this->sourceScanner()->scan($board, $plain, $this->credential(), new RemoteLocation(RemoteLocationKind::Folder, '1'), $this->owner));
        $this->assertNotFound(fn (): SourceScanResult => $this->sourceScanner()->scan($board, $this->provider, $this->credential(), new RemoteLocation(RemoteLocationKind::Folder, '1'), $this->owner));

        // No workspace: no people.
        $lonely = new InMemoryTaskProvider();
        $lonely->addSource(new RemoteSource(RemoteLocation::list(self::LIST), 'Backlog'), [self::LIST]);
        $none = $this->sourceScanner()->scan($board, $lonely, $this->credential(), RemoteLocation::list(self::LIST), $this->owner);
        self::assertSame('', $none->workspaceId);
        self::assertSame([], $none->people->people);
        self::assertNull($none->timeAccess);

        $this->expectException(PeopleMappingDeniedException::class);
        $this->sourceScanner()->scan($board, $this->provider, $this->credential(), RemoteLocation::list(self::LIST), $this->user('mallory@example.test'));
    }

    private function assertNotFound(callable $call): void
    {
        try {
            $call();
            self::fail('expected not_found');
        } catch (ProviderException $exception) {
            self::assertSame(ProviderErrorCode::NotFound, $exception->errorCode);
        }
    }

    private function scanner(int $maxTaskPages = 1, int $maxCommentTasks = 10, bool $timeWindow = true): TaskSyncPeopleScanner
    {
        return new TaskSyncPeopleScanner($this->em, $this->directories(), $this->repository(), $this->resolver, $this->accessChecker(), new MockClock(self::NOW), $maxTaskPages, $maxCommentTasks, $timeWindow, 25);
    }

    private function sourceScanner(): TaskSyncSourceScanner
    {
        return new TaskSyncSourceScanner($this->scanner(), $this->accessChecker());
    }

    private function credential(): ProviderCredential
    {
        return new ProviderCredential(TestCredentialResolver::$secret, 'conn-1');
    }

    /**
     * The workspace (two members), three tasks (creator, assignee, watcher, a checklist item assignee; one without
     * an id), a comment author and a time entry.
     */
    private function script(InMemoryTaskProvider $provider, ?TimeAccess $access = null): void
    {
        $provider->withWorkspace(self::WS, [new RemotePerson('9001', 'User 1', 'user1@example.test', RemoteRole::Owner), new RemotePerson('9002', 'User 2', 'user2@example.test', RemoteRole::Member), new RemotePerson('9006', 'User 6', null, RemoteRole::Guest)], $access);
        $provider->putTask(self::LIST, new RemoteTask(
            task: new ImportedTaskDto(title: 'One', externalId: '86a1x1', priority: TaskPriority::Normal, creator: new RemotePersonRef('9001', 'User 1', 'user1@example.test'), assigneeRefs: [new RemotePersonRef('9002', 'User 2', 'user2@example.test')]),
            remoteUpdatedAt: new DateTimeImmutable('@1790000001'),
            watchers: [new RemotePersonRef('9003', 'User 3', 'user3@example.test')],
            watchersKnown: true,
            checklists: [new RemoteChecklist('c1', 'Steps', 0, [new RemoteChecklistItem('i1', 'Step', 0, false, new RemotePersonRef('9004', 'User 4')), new RemoteChecklistItem('i2', 'Other', 1)])],
            checklistsKnown: true,
        ));
        $provider->putTask(self::LIST, new RemoteTask(new ImportedTaskDto(title: 'No id'), new DateTimeImmutable('@1790000002')));
        $provider->putTask(self::LIST, new RemoteTask(new ImportedTaskDto(title: 'Two', externalId: '86a1x2'), new DateTimeImmutable('@1790000003')));
        $provider->setComments('86a1x1', [
            new RemoteComment('k1', 'Hi', 'user1@example.test', 'User 1', new DateTimeImmutable('@1790000000'), [], new RemotePersonRef('9001', 'User 1', 'user1@example.test')),
            new RemoteComment('k2', 'Hey', null, null, new DateTimeImmutable('@1790000000'), [], new RemotePersonRef('9005', 'User 5')),
            new RemoteComment('k3', 'Legacy', null, 'Someone', new DateTimeImmutable('@1790000000')),
        ]);
        $provider->withTimeEntries([
            new RemoteTimeEntry('t1', '86a1x1', new RemotePersonRef('9001', 'User 1'), new DateTimeImmutable('2026-10-20 10:00:00'), new DateTimeImmutable('2026-10-20 11:00:00'), 3600),
            new RemoteTimeEntry('t2', '86a1x1', new RemotePersonRef('9006', 'User 6'), new DateTimeImmutable('2026-10-21 10:00:00'), new DateTimeImmutable('2026-10-21 11:00:00'), 3600),
            new RemoteTimeEntry('t3', '86a1x1', new RemotePersonRef('9001', 'User 1'), new DateTimeImmutable('2026-01-01 10:00:00'), null, -1, true),
        ]);
    }

    /**
     * @param list<ScannedPerson> $people
     *
     * @return array<array-key, ScannedPerson>
     */
    private function byId(array $people): array
    {
        $byId = [];
        foreach ($people as $person) {
            $byId[$person->providerUserId] = $person;
        }
        ksort($byId);

        return $byId;
    }
}
