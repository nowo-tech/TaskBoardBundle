<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\People;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncComment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncMember;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Event\SyncPeopleReviewedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonAnonymisedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonMappedEvent;
use Nowo\TaskBoardBundle\Sync\People\InvalidMappingException;
use Nowo\TaskBoardBundle\Sync\People\PeopleMappingDeniedException;
use Nowo\TaskBoardBundle\Sync\People\PersonFilter;
use Nowo\TaskBoardBundle\Sync\People\PersonMappingDecision;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;
use stdClass;

use function array_map;
use function json_encode;
use function str_contains;

/**
 * Since 1.25 (§4, §8, §9, §13.1 "Directory"): automatic matching, refresh rules, human decisions, anonymising,
 * pruning and the export of the people directory.
 */
final class PeopleDirectoryTest extends PeopleFunctionalTestCase
{
    private const WS = '9000001';

    public function testAutomaticMatchingVerifiedUnverifiedNoneAndNoEmail(): void
    {
        $board                   = $this->board();
        $alice                   = $this->user('user1@example.test');
        $bob                     = $this->user('user2@example.test');
        $this->resolver->matches = ['user1@example.test' => [$alice, true], 'user2@example.test' => [$bob, false]];
        $service                 = $this->directories();
        $directory               = $service->directoryForDraft($board, 'clickup', self::WS);
        $session                 = $service->session($directory, $board);

        $auto      = $session->person(new RemotePerson('9001', 'User 1', ' USER1@Example.TEST ', RemoteRole::Member), TaskSyncPerson::KIND_MEMBER);
        $suggested = $session->person(new RemotePerson('9002', 'User 2', 'user2@example.test'), TaskSyncPerson::KIND_MEMBER);
        $none      = $session->person(new RemotePersonRef('9003', 'User 3', 'user3@example.test'), TaskSyncPerson::KIND_CREATOR);
        $noEmail   = $session->person(new RemotePersonRef('9004', 'User 4'), TaskSyncPerson::KIND_COMMENT);
        $this->em->flush();

        self::assertSame(TaskSyncPersonMapping::Auto, $auto->getMapping());
        self::assertSame($alice, $auto->getMappedUser());
        self::assertSame('member', $auto->getRemoteRole());
        self::assertSame('user1@example.test', $auto->getEmailNormalized());
        self::assertSame(TaskSyncPersonMapping::Suggested, $suggested->getMapping());
        self::assertSame($bob, $suggested->getSuggestedUser());
        self::assertNull($suggested->getMappedUser());
        self::assertSame(TaskSyncPersonMapping::Unmatched, $none->getMapping());
        self::assertSame(TaskSyncPersonMapping::Unmatched, $noEmail->getMapping());
        self::assertNotContains('', $this->resolver->asked, 'a person without e-mail is never matched');
        self::assertSame(1, $session->stats['people.autoMatched']);
        self::assertSame(1, $session->stats['people.suggested']);
        self::assertSame(4, $session->stats['people.created']);
        self::assertSame(2, $session->stats['people.unmatchedNew']);
        self::assertSame(['people_unmatched_new'], $session->warnings);

        // The same person in the session is one row; a new kind is added to its seen kinds.
        self::assertSame($none, $session->person(new RemotePersonRef('9003', 'User 3'), TaskSyncPerson::KIND_ASSIGNEE));
        self::assertTrue($none->hasSeenKind(TaskSyncPerson::KIND_ASSIGNEE));
        self::assertSame($none, $session->person(new RemotePersonRef('9003', 'User 3'), TaskSyncPerson::KIND_ASSIGNEE));

        // Every automatic change is audited, with ids only.
        $mapped = $this->eventsNamed(TaskBoardEvents::SYNC_PERSON_MAPPED);
        self::assertCount(2, $mapped);
        self::assertInstanceOf(SyncPersonMappedEvent::class, $mapped[0]);
        self::assertSame(SyncPersonMappedEvent::REASON_AUTO, $mapped[0]->reason);
        self::assertNull($mapped[0]->actorId);
        self::assertSame('unmatched', $mapped[0]->previousMapping);
        self::assertSame('auto', $mapped[0]->newMapping);
        self::assertSame((string) $alice->getId(), $mapped[0]->newUserId);
        self::assertInstanceOf(SyncPersonMappedEvent::class, $mapped[1]);
        self::assertSame(SyncPersonMappedEvent::REASON_SUGGESTED, $mapped[1]->reason);
        foreach ($mapped as $event) {
            $payload = (string) json_encode($event);
            self::assertFalse(str_contains($payload, '@'), 'no e-mail in an audit event');
            self::assertFalse(str_contains($payload, 'User '), 'no name in an audit event');
        }

        // A second session finds the rows (one per remote id) and changes nothing.
        $again = $service->session($directory, $board);
        self::assertSame($auto->getId(), $again->person(new RemotePerson('9001', 'User 1', 'user1@example.test'), TaskSyncPerson::KIND_MEMBER)->getId());
        self::assertSame($suggested->getId(), $again->person(new RemotePerson('9002', 'User 2', 'user2@example.test'), TaskSyncPerson::KIND_MEMBER)->getId());
        self::assertSame(['people.seen' => 2], $again->stats, 'seen, nothing else');
        self::assertCount(4, $this->repository()->forDirectory($directory));
    }

    public function testRefreshRulesForAutoManualAndAnonymisedPeople(): void
    {
        $board                   = $this->board();
        $alice                   = $this->user('user1@example.test');
        $carol                   = $this->user('user3@example.test');
        $this->resolver->users   = [$alice, $carol];
        $this->resolver->matches = ['user1@example.test' => [$alice, true], 'user3@example.test' => [$carol, true]];
        $service                 = $this->directories();
        $directory               = $service->directoryForDraft($board, 'clickup', self::WS);
        $session                 = $service->session($directory, $board);
        $auto                    = $session->person(new RemotePerson('9001', 'User 1', 'user1@example.test'), TaskSyncPerson::KIND_MEMBER);
        $manual                  = $session->person(new RemotePerson('9002', 'User 2'), TaskSyncPerson::KIND_MEMBER);
        $gone                    = $session->person(new RemotePerson('9005', 'User 5', 'user3@example.test'), TaskSyncPerson::KIND_MEMBER);
        $this->em->flush();
        $service->applyMapping($directory, [new PersonMappingDecision($manual->getId(), PersonMappingDecision::MANUAL, (string) $alice->getId())], $this->owner, $board);
        self::assertSame(TaskSyncPersonMapping::Manual, $manual->getMapping());
        // A mapped user that is gone (SET NULL) reads as unmapped: the next refresh unmatches it.
        $gone->map(TaskSyncPersonMapping::Auto, null, null, new DateTimeImmutable());

        // The e-mails change in the provider.
        $next = $service->session($directory, $board);
        $next->person(new RemotePerson('9001', 'User 1 renamed', 'user3@example.test'), TaskSyncPerson::KIND_MEMBER);
        $next->person(new RemotePerson('9002', 'User 2', 'someone@example.test'), TaskSyncPerson::KIND_MEMBER);
        $next->person(new RemotePerson('9005', 'User 5', 'user3@example.test'), TaskSyncPerson::KIND_MEMBER);
        $this->em->flush();

        self::assertSame(TaskSyncPersonMapping::Unmatched, $auto->getMapping(), 'an auto mapping whose e-mail changed is dropped, never switched');
        self::assertNull($auto->getMappedUser());
        self::assertNotNull($auto->getEmailChangedAt());
        self::assertSame('User 1 renamed', $auto->getName());
        self::assertSame(TaskSyncPersonMapping::Manual, $manual->getMapping(), 'a human decision is never overridden');
        self::assertSame($alice, $manual->getMappedUser());
        self::assertNull($manual->getEmailChangedAt(), 'the first e-mail is no change');
        self::assertSame(TaskSyncPersonMapping::Unmatched, $gone->getMapping());
        self::assertSame(2, $next->stats['people.autoUnmatched']);
        self::assertSame(1, $next->stats['people.changedEmail']);
        self::assertSame(['person_auto_unmatched'], $next->warnings);
        self::assertSame(3, $next->stats['people.seen']);

        // The People page filters (contract §2.2): q on the name or the e-mail, e-mail changed [since].
        $ids = fn (PersonFilter $filter): array => array_map(static fn (TaskSyncPerson $p): string => $p->getProviderUserId(), $this->repository()->forDirectory($directory, $filter));
        self::assertSame(['9001'], $ids(new PersonFilter(q: ' RENAMED ')));
        self::assertSame(['9001', '9005'], $ids(new PersonFilter(q: 'User3@')));
        self::assertSame([], $ids(new PersonFilter(q: '%')), 'LIKE wildcards are literal');
        self::assertSame([], $ids(new PersonFilter(q: 'user_')), '"_" is literal, not one character');
        self::assertSame(['9001'], $ids(new PersonFilter(q: 'user', changed: true)));
        self::assertSame(['9001'], $ids(new PersonFilter(changedSince: new DateTimeImmutable('-1 hour'))));
        self::assertSame([], $ids(new PersonFilter(changedSince: new DateTimeImmutable('+1 hour'))));
        self::assertSame(['9001'], $ids(new PersonFilter([TaskSyncPersonMapping::Unmatched], 'user 1')));

        // A person whose e-mail changed is never matched automatically again.
        $later = $service->session($directory, $board);
        $later->person(new RemotePerson('9001', 'User 1', 'user3@example.test'), TaskSyncPerson::KIND_MEMBER);
        self::assertSame(TaskSyncPersonMapping::Unmatched, $auto->getMapping());

        // Anonymised: never refreshed nor matched again; the provider id stays.
        $this->connection($board, $directory);
        $service->anonymise($auto, $this->owner);
        $service->anonymise($auto, $this->owner);
        $after = $service->session($directory, $board);
        $after->person(new RemotePerson('9001', 'New name', 'user1@example.test', RemoteRole::Admin), TaskSyncPerson::KIND_TIME_ENTRY);
        $this->em->flush();
        self::assertTrue($auto->isAnonymised());
        self::assertSame('former:1', $auto->getName());
        self::assertSame(1, $auto->anonymousNumber());
        self::assertNull($auto->getEmail());
        self::assertNull($auto->getEmailNormalized());
        self::assertSame('9001', $auto->getProviderUserId());
        self::assertSame(TaskSyncPersonMapping::Unmatched, $auto->getMapping());
        self::assertTrue($auto->hasSeenKind(TaskSyncPerson::KIND_TIME_ENTRY));
        self::assertNull($manual->anonymousNumber());
        $anonymised = $this->eventsNamed(TaskBoardEvents::SYNC_PERSON_ANONYMISED);
        self::assertCount(1, $anonymised);
        self::assertInstanceOf(SyncPersonAnonymisedEvent::class, $anonymised[0]);
        self::assertSame($auto->getId(), $anonymised[0]->personId);
        self::assertSame($directory->getId(), $anonymised[0]->directoryId);
        self::assertSame((string) $this->owner->getId(), $anonymised[0]->actorId);
    }

    public function testHumanDecisions(): void
    {
        $board                   = $this->board();
        $alice                   = $this->user('user1@example.test');
        $bob                     = $this->user('user2@example.test');
        $this->resolver->users   = [$alice, $bob];
        $this->resolver->matches = ['user2@example.test' => [$bob, false]];
        $service                 = $this->directories();
        $directory               = $service->directoryForDraft($board, 'clickup', self::WS);
        $session                 = $service->session($directory, $board);
        $one                     = $session->person(new RemotePerson('9001', 'User 1', 'old@example.test'), TaskSyncPerson::KIND_MEMBER);
        $two                     = $session->person(new RemotePerson('9002', 'User 2', 'user2@example.test'), TaskSyncPerson::KIND_MEMBER);
        $three                   = $session->person(new RemotePerson('9003', 'User 3', 'new@example.test'), TaskSyncPerson::KIND_MEMBER);
        $this->em->flush();
        $this->connection($board, $directory);
        $this->events = [];

        // Many-to-one: two remote identities (old and new e-mail) to the same teammate.
        $changed = $service->applyMapping($directory, [
            new PersonMappingDecision($one->getId(), PersonMappingDecision::MANUAL, (string) $alice->getId()),
            new PersonMappingDecision($three->getId(), PersonMappingDecision::MANUAL, (string) $alice->getId()),
            new PersonMappingDecision($two->getId(), PersonMappingDecision::CONFIRM),
        ], $this->owner);
        self::assertSame([$one, $three, $two], $changed);
        self::assertSame($alice, $one->getMappedUser());
        self::assertSame($alice, $three->getMappedUser());
        self::assertSame($bob, $two->getMappedUser());
        self::assertSame(TaskSyncPersonMapping::Manual, $two->getMapping());
        self::assertNotNull($two->getMappedAt());
        self::assertSame($this->owner->getId(), $two->getMappedBy() instanceof AppUser ? $two->getMappedBy()->getId() : null);
        self::assertSame([SyncPersonMappedEvent::REASON_MANUAL, SyncPersonMappedEvent::REASON_MANUAL, SyncPersonMappedEvent::REASON_CONFIRM], array_map(static fn (object $e): string => $e instanceof SyncPersonMappedEvent ? $e->reason : '', $this->eventsNamed(TaskBoardEvents::SYNC_PERSON_MAPPED)));

        // The same decision again changes nothing; external is a decision too.
        self::assertSame([], $service->applyMapping($directory, [new PersonMappingDecision($one->getId(), PersonMappingDecision::MANUAL, (string) $alice->getId())], $this->owner));
        self::assertSame([$one], $service->applyMapping($directory, [new PersonMappingDecision($one->getId(), PersonMappingDecision::EXTERNAL)], $this->owner));
        self::assertSame(TaskSyncPersonMapping::External, $one->getMapping());
        self::assertNull($one->getMappedUser());
        self::assertSame([], $service->applyMapping($directory, [new PersonMappingDecision($one->getId(), PersonMappingDecision::EXTERNAL)], $this->owner));

        // Refusals.
        $this->assertInvalid('not_candidate', fn (): array => $service->applyMapping($directory, [new PersonMappingDecision($one->getId(), PersonMappingDecision::MANUAL, '999999')], $this->owner));
        $this->resolver->notCandidates = [(string) $bob->getId()];
        $this->assertInvalid('not_candidate', fn (): array => $service->applyMapping($directory, [new PersonMappingDecision($one->getId(), PersonMappingDecision::MANUAL, (string) $bob->getId())], $this->owner));
        $this->resolver->notCandidates = [];
        $this->assertInvalid('no_suggestion', fn (): array => $service->applyMapping($directory, [new PersonMappingDecision($one->getId(), PersonMappingDecision::CONFIRM)], $this->owner));
        $this->assertInvalid('unknown_person', fn (): array => $service->applyMapping($directory, [new PersonMappingDecision('nope', PersonMappingDecision::EXTERNAL)], $this->owner));
        $this->assertInvalid('invalid_kind', fn (): array => $service->applyMapping($directory, [new PersonMappingDecision($one->getId(), 'promote')], $this->owner));
        $other = $service->directoryForDraft($this->board('Other'), 'clickup', '9000002');
        $this->assertInvalid('unknown_person', fn (): array => $service->applyMapping($other, [new PersonMappingDecision($one->getId(), PersonMappingDecision::EXTERNAL)], $this->owner, $board));
        $service->forget($one, null);
        $this->assertInvalid('anonymised', fn (): array => $service->applyMapping($directory, [new PersonMappingDecision($one->getId(), PersonMappingDecision::MANUAL, (string) $alice->getId())], $this->owner));

        // A suggestion whose user stopped being a candidate cannot be confirmed.
        $session2 = $service->session($directory, $board);
        $four     = $session2->person(new RemotePerson('9004', 'User 4', 'user2@example.test'), TaskSyncPerson::KIND_MEMBER);
        $this->em->flush();
        self::assertSame(TaskSyncPersonMapping::Suggested, $four->getMapping());
        $this->resolver->notCandidates = [(string) $bob->getId()];
        $this->assertInvalid('not_candidate', fn (): array => $service->applyMapping($directory, [new PersonMappingDecision($four->getId(), PersonMappingDecision::CONFIRM)], $this->owner));
    }

    public function testOnlyActorsManagingEveryBoardOfTheDirectoryMayDecide(): void
    {
        $board     = $this->board();
        $mallory   = $this->user('mallory@example.test');
        $foreign   = $this->board('Foreign', creator: $mallory);
        $service   = $this->directories();
        $directory = $service->directoryForDraft($board, 'clickup', self::WS);

        // No board uses the directory yet and no draft board: nobody may manage it.
        $this->assertDenied(fn () => $service->markReviewed($directory, $this->owner));
        // The draft board of the dialog, in the same scope, is enough.
        $service->markReviewed($directory, $this->owner, $board);
        self::assertNotNull($directory->getReviewedAt());
        // A draft board of another scope does not count.
        $this->resolver->scopes[$foreign->getId()] = 'scope:b';
        $this->assertDenied(static fn () => $service->markReviewed($directory, $mallory, $foreign));

        // Two boards share the directory; the owner manages only one of them.
        $this->connection($board, $directory);
        $this->connection($foreign, $directory);
        $person = $service->session($directory, $board)->person(new RemotePerson('9001', 'User 1'), TaskSyncPerson::KIND_MEMBER);
        $this->em->flush();
        $this->assertDenied(fn (): array => $service->applyMapping($directory, [new PersonMappingDecision($person->getId(), PersonMappingDecision::EXTERNAL)], $this->owner));
        $this->assertDenied(fn () => $service->anonymise($person, $this->owner));
        self::assertSame(TaskSyncPersonMapping::Unmatched, $person->getMapping());
    }

    public function testMarkReviewedAuditsTheCounts(): void
    {
        $board     = $this->board();
        $service   = $this->directories();
        $directory = $service->directoryForDraft($board, 'clickup', self::WS);
        $session   = $service->session($directory, $board);
        $session->person(new RemotePerson('9001', 'User 1'), TaskSyncPerson::KIND_MEMBER);
        $session->person(new RemotePerson('9002', 'User 2'), TaskSyncPerson::KIND_MEMBER);
        $this->em->flush();
        self::assertSame(2, $service->unreviewedCount($directory));

        $service->markReviewed($directory, $this->owner, $board);

        self::assertSame($this->owner->getId(), $directory->getReviewedBy() instanceof AppUser ? $directory->getReviewedBy()->getId() : null);
        $reviewed = $this->eventsNamed(TaskBoardEvents::SYNC_PEOPLE_REVIEWED);
        self::assertCount(1, $reviewed);
        self::assertInstanceOf(SyncPeopleReviewedEvent::class, $reviewed[0]);
        self::assertSame(['unmatched' => 2], $reviewed[0]->counts);
        self::assertSame($directory->getId(), $reviewed[0]->directoryId);
        self::assertSame((string) $this->owner->getId(), $reviewed[0]->actorId);
    }

    public function testDirectoriesAreIsolatedPerScopeAndAttachedToConnections(): void
    {
        $board                                   = $this->board();
        $other                                   = $this->board('Other');
        $service                                 = $this->directories();
        $this->resolver->scopes[$other->getId()] = 'scope:b';

        $a = $service->directoryForDraft($board, 'clickup', self::WS);
        $b = $service->directoryForDraft($other, 'clickup', self::WS);
        self::assertNotSame($a->getId(), $b->getId(), 'same workspace, two scopes: two directories');
        self::assertSame($a->getId(), $service->directoryForDraft($board, 'clickup', self::WS)->getId());
        self::assertSame('scope:a', $a->getScopeKey());
        self::assertSame(self::WS, $a->getWorkspaceId());
        self::assertSame('clickup', $a->getProvider());
        self::assertGreaterThan(0, $a->getCreatedAt()->getTimestamp());
        $pa = $service->session($a, $board)->person(new RemotePerson('9001', 'User 1'), TaskSyncPerson::KIND_MEMBER);
        $pb = $service->session($b, $other)->person(new RemotePerson('9001', 'User 1'), TaskSyncPerson::KIND_MEMBER);
        $this->em->flush();
        self::assertNotSame($pa->getId(), $pb->getId());
        self::assertSame($pa, $this->repository()->findOneByProviderUserId($a, '9001'));
        self::assertSame($pa, $this->repository()->findPerson($pa->getId()));
        self::assertSame($a, $pa->getDirectory());
        self::assertGreaterThan(0, $pa->getFirstSeenAt()->getTimestamp());

        $this->resolver->scopes[$other->getId()] = 'Not A Scope!';
        try {
            $service->directoryForDraft($other, 'clickup', self::WS);
            self::fail('an invalid scope is refused');
        } catch (InvalidMappingException $exception) {
            self::assertSame('invalid_scope', $exception->reason);
        }
        $this->resolver->scopes[$other->getId()] = 'scope:b';

        // A connection gets its directory on first use (workspace looked up once), then keeps it.
        $credential = new ProviderCredential(TestCredentialResolver::$secret, 'conn-1');
        $connection = $this->connection($board);
        $without    = $service->directoryFor($connection, $this->provider, $credential);
        self::assertNull($without, 'a provider without people');
        $this->provider->withWorkspace(self::WS);
        $attached = $service->directoryFor($connection, $this->provider, $credential);
        self::assertNotNull($attached);
        self::assertSame($a->getId(), $attached->getId());
        self::assertSame($attached, $connection->getDirectory());
        self::assertSame($attached, $service->directoryFor($connection, $this->provider, $credential));
        $unknown = $this->connection($other, null, 'L2');
        self::assertNotNull($service->directoryFor($unknown, (new InMemoryTaskProvider())->withWorkspace(self::WS), $credential));
        $none = $this->connection($this->board('Third'), null, 'L3');
        self::assertNull($service->directoryFor($none, new class((new InMemoryTaskProvider())->withWorkspace(self::WS)) extends PeopleOnlyProvider {
            public function workspaceOf(ProviderCredential $credential, string $containerId): ?string
            {
                return null;
            }
        }, $credential));
    }

    public function testTheSearchMatchesUnderscorePercentAndBangLiterally(): void
    {
        $board     = $this->board();
        $service   = $this->directories();
        $directory = $service->directoryForDraft($board, 'clickup', self::WS);
        $session   = $service->session($directory, $board);
        $session->person(new RemotePerson('9101', 'First Last', 'first_last@example.test'), TaskSyncPerson::KIND_MEMBER);
        $session->person(new RemotePerson('9102', 'FirstXLast', 'firstxlast@example.test'), TaskSyncPerson::KIND_MEMBER);
        $session->person(new RemotePerson('9103', '100% done!'), TaskSyncPerson::KIND_MEMBER);
        $session->person(new RemotePerson('9104', '100 percent'), TaskSyncPerson::KIND_MEMBER);
        $this->em->flush();
        $ids = fn (string $q): array => array_map(static fn (TaskSyncPerson $p): string => $p->getProviderUserId(), $this->repository()->forDirectory($directory, new PersonFilter(q: $q)));

        self::assertSame(['9101'], $ids('first_last@'), 'an underscore in an e-mail is found, and only literally');
        self::assertSame(['9101', '9102'], $ids('first'));
        self::assertSame(['9103'], $ids('100%'));
        self::assertSame(['9103'], $ids('done!'));
        self::assertSame(['9103'], $ids('% done!'));
        self::assertSame([], $ids('!%'));
    }

    public function testMembersAreReadPageByPage(): void
    {
        $board    = $this->board();
        $provider = new InMemoryTaskProvider(pageSize: 1);
        $provider->withWorkspace(self::WS, [new RemotePerson('9001', 'User 1', null, RemoteRole::Owner), new RemotePerson('9002', 'User 2', null, RemoteRole::Guest)]);
        $service   = $this->directories();
        $directory = $service->directoryForDraft($board, 'clickup', self::WS);
        $session   = $service->session($directory, $board);

        $service->refreshMembers($session, $provider, new ProviderCredential(TestCredentialResolver::$secret, 'conn-1'));

        $people = $this->repository()->forDirectory($directory);
        self::assertCount(2, $people);
        self::assertSame(['owner', 'guest'], [$people[0]->getRemoteRole(), $people[1]->getRemoteRole()]);
        self::assertTrue($people[0]->hasSeenKind(TaskSyncPerson::KIND_MEMBER));
        self::assertCount(1, $this->repository()->forDirectory($directory, new PersonFilter(), 1, 1));
        self::assertCount(2, $this->repository()->forDirectory($directory, PersonFilter::mappings(TaskSyncPersonMapping::Unmatched)));
        self::assertSame([], $this->repository()->forDirectory($directory, PersonFilter::mappings(TaskSyncPersonMapping::Manual)));
    }

    public function testPruneDeletesUnreferencedPeopleAndAnonymisesTheRest(): void
    {
        $board     = $this->board();
        $service   = $this->directories();
        $directory = $service->directoryForDraft($board, 'clickup', self::WS);
        $session   = $service->session($directory, $board);
        $creator   = $session->person(new RemotePerson('9001', 'User 1', 'user1@example.test'), TaskSyncPerson::KIND_CREATOR);
        $author    = $session->person(new RemotePerson('9002', 'User 2'), TaskSyncPerson::KIND_COMMENT);
        $member    = $session->person(new RemotePerson('9003', 'User 3'), TaskSyncPerson::KIND_ASSIGNEE);
        $loose     = $session->person(new RemotePerson('9004', 'User 4'), TaskSyncPerson::KIND_MEMBER);
        $this->em->flush();
        // The imported rows live on a connection that does not use the directory (so it stays after the unlink).
        $keeper = $this->connection($this->board('Keeper'), null, 'L9');
        $task   = $this->task($keeper->getBoard(), 'Imported');
        $item   = $this->item($keeper, $task, 'T1');
        $item->setCreatorPerson($creator);
        $comment = new TaskComment($task, $this->owner, 'Hello');
        $this->em->persist($comment);
        $this->em->persist((new TaskSyncComment($item, $comment, 'c1', 'h', 'h'))->setAuthorPerson($author));
        $taskMember = new TaskMember($task, $this->owner, TaskMemberRole::Assignee);
        $task->addMember($taskMember);
        $this->em->persist($taskMember);
        $syncMember = new TaskSyncMember($item, $taskMember, $member, 'assignee');
        $this->em->persist($syncMember);
        $this->em->flush();
        self::assertSame($item, $syncMember->getItem());
        self::assertSame($taskMember, $syncMember->getMember());
        self::assertSame($member, $syncMember->getPerson());
        self::assertSame('assignee', $syncMember->getMemberRole());
        self::assertGreaterThan(0, $syncMember->getCreatedAt()->getTimestamp());
        self::assertNotSame('', $syncMember->getId());

        // Computed counts and the references.
        $counts = $this->repository()->counts($directory);
        self::assertSame(1, $counts[$creator->getId()]['creator']);
        self::assertSame(1, $counts[$author->getId()]['comment']);
        self::assertSame(1, $counts[$member->getId()]['assignee']);
        self::assertArrayNotHasKey($loose->getId(), $counts);
        self::assertSame([$item], $this->repository()->itemsAfter($keeper, '', 10));
        self::assertSame([], $this->repository()->itemsAfter($keeper, $item->getId(), 10));
        self::assertCount(1, $this->repository()->commentsOf($author, [$item]));
        self::assertSame([], $this->repository()->commentsOf($author, []));
        self::assertSame([$syncMember], $this->repository()->membersOf($member, [$item]));
        self::assertSame([], $this->repository()->membersOf($member, []));
        self::assertSame([$syncMember], $this->repository()->membersOfItem($item));

        // While a connection uses the directory, nothing is pruned.
        $connection = $this->connection($board, $directory);
        self::assertSame([$connection], $this->repository()->connectionsOf($directory));
        self::assertSame(0, $service->pruneDirectory($directory));
        $this->em->remove($connection);
        $this->em->flush();

        // The last connection went: the loose person goes, the referenced ones are anonymised, the directory stays.
        self::assertSame(1, $service->pruneDirectory($directory));
        $this->em->clear();
        self::assertNull($this->em->find(TaskSyncPerson::class, $loose->getId()));
        foreach ([$creator, $author, $member] as $person) {
            $kept = $this->em->find(TaskSyncPerson::class, $person->getId());
            self::assertInstanceOf(TaskSyncPerson::class, $kept);
            self::assertTrue($kept->isAnonymised());
        }
        self::assertNotNull($this->em->find(TaskSyncDirectory::class, $directory->getId()));
        self::assertCount(3, $this->eventsNamed(TaskBoardEvents::SYNC_PERSON_ANONYMISED));

        // A directory nobody references goes with its people.
        $other = $this->freshBoard($board);
        $empty = $service->directoryForDraft($other, 'clickup', '9000002');
        $service->session($empty, $other)->person(new RemotePerson('9009', 'User 9'), TaskSyncPerson::KIND_MEMBER);
        $this->em->flush();
        self::assertSame(1, $service->pruneDirectory($empty));
        $this->em->clear();
        self::assertNull($this->em->find(TaskSyncDirectory::class, $empty->getId()));
    }

    public function testExportsAndLinkedPeople(): void
    {
        $board                   = $this->board();
        $alice                   = $this->user('user1@example.test');
        $this->resolver->matches = ['user1@example.test' => [$alice, true], 'user2@example.test' => [$alice, false]];
        $service                 = $this->directories();
        $directory               = $service->directoryForDraft($board, 'clickup', self::WS);
        $session                 = $service->session($directory, $board);
        $mapped                  = $session->person(new RemotePerson('9001', 'User 1', 'user1@example.test', RemoteRole::Admin), TaskSyncPerson::KIND_MEMBER);
        $suggested               = $session->person(new RemotePerson('9002', 'User 2', 'user2@example.test'), TaskSyncPerson::KIND_MEMBER);
        $session->person(new RemotePerson('9003', 'User 3'), TaskSyncPerson::KIND_MEMBER);
        $this->em->flush();

        self::assertSame([$mapped, $suggested], $this->sortById($this->repository()->linkedToUser($alice), [$mapped, $suggested]));
        self::assertSame([$mapped], $this->repository()->linkedToUser($alice, false));
        $export = $this->repository()->exportFor($alice);
        self::assertCount(2, $export);
        $row = $export[0]['providerUserId'] === '9001' ? $export[0] : $export[1];
        self::assertSame('User 1', $row['name']);
        self::assertSame('user1@example.test', $row['email']);
        self::assertSame('admin', $row['remoteRole']);
        self::assertSame('auto', $row['mapping']);
        self::assertSame((string) $alice->getId(), $row['mappedUserId']);
        self::assertSame('clickup', $row['provider']);
        self::assertNotNull($row['mappedAt']);
        self::assertNull($row['anonymisedAt']);
        self::assertCount(3, $this->repository()->exportDirectory($directory));
        self::assertSame(['auto' => 1, 'suggested' => 1, 'unmatched' => 1], $this->sorted($this->repository()->mappingCounts($directory)));
        self::assertSame(2, $this->repository()->unreviewedCount($directory));
        self::assertSame($directory, $this->repository()->findDirectory('clickup', 'scope:a', self::WS));
        self::assertSame([], $this->repository()->connectionsOf($directory));
    }

    public function testTheInternalHelpersAcceptNonEntityActors(): void
    {
        $board     = $this->board();
        $service   = $this->directories();
        $directory = $service->directoryForDraft($board, 'clickup', self::WS);
        $person    = $service->session($directory, $board)->person(new RemotePerson('9001', 'User 1'), TaskSyncPerson::KIND_MEMBER);
        $this->em->flush();

        // Not a mapped class, and a mapped user without an id yet: kept as is (never flushed here).
        $service->changeMapping($person, TaskSyncPersonMapping::External, null, new stdClass(), SyncPersonMappedEvent::REASON_EXTERNAL);
        self::assertInstanceOf(stdClass::class, $person->getMappedBy());
        $unsaved = new AppUser('new@example.test');
        $service->changeMapping($person, TaskSyncPersonMapping::External, null, $unsaved, SyncPersonMappedEvent::REASON_EXTERNAL);
        self::assertSame($unsaved, $person->getMappedBy());
        $this->em->clear();
    }

    public function testProjectionPendingReassignAndPrefetch(): void
    {
        $board     = $this->board();
        $mallory   = $this->user('mallory@example.test');
        $service   = $this->directories();
        $directory = $service->directoryForDraft($board, 'clickup', self::WS);
        $session   = $service->session($directory, $board);
        $viewer    = $session->person(new RemotePerson('9001', 'User 1'), TaskSyncPerson::KIND_MEMBER | TaskSyncPerson::KIND_CREATOR);
        $outsider  = $session->person(new RemotePerson('9002', 'User 2'), TaskSyncPerson::KIND_MEMBER);
        $nobody    = $session->person(new RemotePerson('9003', 'User 3'), TaskSyncPerson::KIND_MEMBER);
        $viewer->map(TaskSyncPersonMapping::Manual, $this->owner, null, new DateTimeImmutable());
        $outsider->map(TaskSyncPersonMapping::Auto, $mallory, null, new DateTimeImmutable());
        $this->em->flush();

        // A mapping never grants access: only a user who can view the board is written.
        self::assertSame($this->owner, $session->projected($viewer));
        self::assertSame($this->owner, $session->projected($viewer), 'memoised per user');
        self::assertNull($session->projected($outsider));
        self::assertNull($session->projected($nobody));
        self::assertNull($session->projected(null));
        self::assertSame(TaskSyncPerson::KIND_MEMBER | TaskSyncPerson::KIND_CREATOR, $viewer->getSeenKinds());
        $session->warn('a');
        $session->warn('a');
        self::assertSame(['people_unmatched_new', 'a'], $session->warnings);

        // The pending re-association stored on the person.
        self::assertNull($viewer->getReassignId());
        $viewer->setPendingReassign('r-1', $mallory, false);
        self::assertSame('r-1', $viewer->getReassignId());
        self::assertSame($mallory, $viewer->getReassignToUser());
        self::assertFalse($viewer->isReassignExternal());
        $viewer->setPendingReassign('r-2', $mallory, true);
        self::assertTrue($viewer->isReassignExternal());
        $viewer->setPendingReassign(null, $mallory, true);
        self::assertNull($viewer->getReassignToUser());
        self::assertFalse($viewer->isReassignExternal());

        // Anonymising twice keeps the first number.
        $nobody->anonymise(new DateTimeImmutable());
        self::assertSame('former:1', $nobody->anonymise(new DateTimeImmutable())->getName());

        // candidate() needs at least one board.
        $this->assertInvalid('not_candidate', fn (): object => $service->candidate([], (string) $this->owner->getId()));

        // The batch prefetch of tasks.
        $connection = $this->connection($board, $directory);
        $item       = $this->item($connection, $this->task($board, 'Imported'), 'T1');
        $this->repository()->prefetchTasks([]);
        $this->repository()->prefetchTasks([$item]);
        self::assertSame([$item], $this->repository()->itemsAfter($connection, '', 5));
    }

    private function assertInvalid(string $reason, callable $call): void
    {
        try {
            $call();
            self::fail('expected ' . $reason);
        } catch (InvalidMappingException $exception) {
            self::assertSame($reason, $exception->reason);
            self::assertStringContainsString($reason, $exception->getMessage());
        }
    }

    private function assertDenied(callable $call): void
    {
        try {
            $call();
            self::fail('expected a denial');
        } catch (PeopleMappingDeniedException $exception) {
            self::assertStringNotContainsString('@', $exception->getMessage());
        }
    }

    /**
     * @param list<TaskSyncPerson> $people
     * @param list<TaskSyncPerson> $order
     *
     * @return list<TaskSyncPerson>
     */
    private function sortById(array $people, array $order): array
    {
        $byId = [];
        foreach ($people as $person) {
            $byId[$person->getId()] = $person;
        }
        $sorted = [];
        foreach ($order as $person) {
            if (isset($byId[$person->getId()])) {
                $sorted[] = $byId[$person->getId()];
            }
        }

        return $sorted;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
